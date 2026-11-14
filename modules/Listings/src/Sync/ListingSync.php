<?php

declare(strict_types=1);

namespace Modules\Listings\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Listings\Events\ListingsChanged;
use Modules\Listings\Feed\FeedThrottled;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Jobs\FetchListingPhotos;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsSource;

/**
 * Keeps one MLS in step (specs/websites/02): a full replication first (resumable from the last committed page),
 * then incremental changes with a 2-minute overlap so no record is missed at page boundaries. The cursor only moves
 * after a page is committed. Requests stay under the MLS's hourly limit; a 429 backs off exponentially.
 */
final class ListingSync
{
    public const OVERLAP_SECONDS = 120;

    public const MAX_PAGES_PER_RUN = 50;

    public function __construct(private readonly ResoFeed $feed, private readonly Photos $photos) {}

    /**
     * @return array{kind: string, result: string, received: int, changed: int}
     */
    public function run(MlsSource $source, int $pageSize = 200): array
    {
        $source->refresh();
        $kind = $source->full_sync_done ? 'incremental' : 'full';
        if ($source->backoff_until !== null && $source->backoff_until->isFuture()) {
            return ['kind' => $kind, 'result' => 'waiting', 'received' => 0, 'changed' => 0];
        }
        $run = DB::connection(Connections::listings())->table('mls_sync_runs')->insertGetId(['mls_source_id' => $source->id, 'kind' => $kind, 'result' => 'running', 'started_at' => now()]);
        $received = 0;
        $changed = 0;
        $result = 'ok';
        $error = null;

        try {
            $next = $source->next_link;
            $since = $source->cursor !== null
                ? ($kind === 'full' ? $source->cursor : CarbonImmutable::parse($source->cursor)->subSeconds(self::OVERLAP_SECONDS)->format('Y-m-d\TH:i:s\Z'))
                : null;
            for ($pages = 0; $pages < self::MAX_PAGES_PER_RUN; $pages++) {
                if (! $this->allowRequest($source)) {
                    $result = 'throttled';
                    break;
                }
                $page = $this->feed->changes($source, $since, $next, $pageSize);
                $received += count($page->records);
                $changed += $this->commit($source, $page->records, $page->next);
                $next = $page->next;
                if ($next === null) {
                    break;
                }
            }
            if ($next !== null && $result === 'ok') {
                $result = 'partial'; // more pages next run
            }
            $source->refresh();
            $source->forceFill(['last_success_at' => CarbonImmutable::now(), 'failures' => 0, 'last_error' => null, 'backoff_until' => null,
                'status' => $source->status === 'setup' || $source->status === 'error' ? 'active' : $source->status,
                'full_sync_done' => $source->full_sync_done || ($kind === 'full' && $next === null)])->save();
        } catch (FeedThrottled $e) {
            $result = 'throttled';
            $source->refresh()->forceFill(['backoff_until' => CarbonImmutable::now()->addSeconds(min(3600, max($e->retryAfterSeconds, 60) * (2 ** min($source->failures, 5)))),
                'failures' => min(255, $source->failures + 1)])->save();
        } catch (\Throwable $e) {
            report($e);
            $result = 'failed';
            $error = mb_substr($e->getMessage(), 0, 300);
            $failures = min(255, $source->refresh()->failures + 1);
            $source->forceFill(['failures' => $failures, 'last_error' => $error, 'backoff_until' => CarbonImmutable::now()->addMinutes(min(60, 2 ** min($failures, 6))),
                'status' => $failures >= 5 ? 'error' : $source->status])->save();
        }

        DB::connection(Connections::listings())->table('mls_sync_runs')->where('id', $run)->update(['received' => $received, 'changed' => $changed, 'result' => $result,
            'error' => $error, 'finished_at' => now()]);

        return ['kind' => $kind, 'result' => $result, 'received' => $received, 'changed' => $changed];
    }

    /**
     * Listings the MLS no longer shows as on the market go off-market (daily). Refuses to act on an empty answer
     * when we hold on-market listings: an empty list is far more likely an outage than every listing vanishing.
     */
    public function reconcile(MlsSource $source): int
    {
        $keys = $this->feed->activeKeys($source);
        $ours = Listing::query()->where('mls_source_id', $source->id)->whereIn('standard_status', Listing::ON_MARKET)->whereNull('off_market_at');
        if ($keys === [] && (clone $ours)->count() > 0) {
            throw new \RuntimeException('The MLS returned no active listings; nothing was removed.');
        }
        $gone = (clone $ours)->get(['id', 'listing_key'])->reject(fn (Listing $l): bool => in_array($l->listing_key, $keys, true));
        if ($gone->isNotEmpty()) {
            Listing::query()->whereIn('id', $gone->pluck('id'))->update(['standard_status' => 'Off Market', 'off_market_at' => now(), 'updated_at' => now()]);
            ListingsChanged::dispatch($source->id, array_values($gone->pluck('id')->map(fn ($v): int => (int) $v)->all()), 'removed');
        }
        $source->forceFill(['last_reconciled_at' => CarbonImmutable::now()])->save();
        DB::connection(Connections::listings())->table('mls_sync_runs')->insert(['mls_source_id' => $source->id, 'kind' => 'reconcile', 'received' => count($keys),
            'removed' => $gone->count(), 'result' => 'ok', 'started_at' => now(), 'finished_at' => now()]);

        return $gone->count();
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function commit(MlsSource $source, array $records, ?string $next): int
    {
        $changedIds = [];
        $needPhotos = [];
        $cursor = $source->cursor;
        DB::connection(Connections::listings())->transaction(function () use ($source, $records, $next, &$changedIds, &$needPhotos, &$cursor): void {
            foreach ($records as $record) {
                $row = ResoMapper::map($record, $source->key);
                if (($row['listing_key'] ?? null) === null) {
                    continue;
                }
                $existing = Listing::query()->where('mls_source_id', $source->id)->where('listing_key', $row['listing_key'])->first();
                if ($existing !== null && $existing->modification_timestamp !== null && $row['modification_timestamp'] !== null
                    && $existing->modification_timestamp->format('Y-m-d H:i:s') === $row['modification_timestamp']) {
                    continue; // already have this version (overlap)
                }
                $listing = $existing ?? new Listing(['mls_source_id' => $source->id, 'ulid' => (string) Str::ulid()]);
                $oldPrice = $existing?->list_price_cents;
                if ($existing !== null && $row['list_price_cents'] !== null && $oldPrice !== $row['list_price_cents']) {
                    $row['price_changed_at'] = $row['modification_timestamp'] ?? now()->format('Y-m-d H:i:s');
                }
                $listing->forceFill($row + ['mls_source_id' => $source->id])->save();
                if (isset($row['price_changed_at'])) {
                    DB::connection(Connections::listings())->table('listing_price_changes')->insert(['listing_id' => $listing->id, 'old_cents' => $oldPrice,
                        'new_cents' => $row['list_price_cents'], 'changed_at' => $row['price_changed_at']]);
                }
                if ($this->photos->syncList($listing, $record)) {
                    $needPhotos[] = $listing->id;
                }
                DB::connection(Connections::listings())->table('listing_payloads')->updateOrInsert(['listing_id' => $listing->id],
                    ['payload' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 'received_at' => now()]);
                $changedIds[] = $listing->id;
                $ts = isset($record['ModificationTimestamp']) && is_string($record['ModificationTimestamp']) ? CarbonImmutable::parse($record['ModificationTimestamp'])->utc()->format('Y-m-d\TH:i:s\Z') : null;
                if ($ts !== null && ($cursor === null || $ts > $cursor)) {
                    $cursor = $ts;
                }
            }
            // The cursor and next page move only with the data they describe.
            $source->forceFill(['cursor' => $cursor, 'next_link' => $next])->save();
        });
        foreach ($needPhotos as $id) {
            FetchListingPhotos::dispatch($id);
        }
        if ($changedIds !== []) {
            ListingsChanged::dispatch($source->id, $changedIds, 'changed');
        }

        return count($changedIds);
    }

    private function allowRequest(MlsSource $source): bool
    {
        $key = 'mls-requests:'.$source->id;
        if (RateLimiter::tooManyAttempts($key, max(1, $source->rate_limit_per_hour ?: 7200))) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }
}
