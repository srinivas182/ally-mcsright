<?php

declare(strict_types=1);

namespace Modules\Listings\Search;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Services\OutsideServices;
use Modules\Listings\Models\Listing;
use Modules\Listings\Search\Engines\MeilisearchEngine;
use Modules\Listings\Search\Engines\OpenSearchEngine;
use Modules\Listings\Search\Engines\SearchEngine;
use Modules\Listings\Search\Engines\TypesenseEngine;
use Throwable;

/**
 * The fast listing search server (SR1, C05, specs/websites/02), set up by HQ in Outside services → Fast listing
 * search: Typesense (recommended), Meilisearch or OpenSearch.
 *
 * - Off: searches use the database, as before.
 * - Test: the database still answers; after each search the server answers the same question in the background and
 *   the two are compared (how many of the first results match, and how fast each was), shown to HQ.
 * - Live: the server answers (typo-tolerant, map and radius). If it fails or is slow, the database answers instead.
 *
 * Only on-market listings that may be shown on the internet are indexed. The server returns ids only; listings are
 * then loaded through the MLS licence and display rules, so nothing the rules hide can appear.
 */
final class SearchIndex
{
    private const COMPARE = 'listings:search-compare';

    /** @return array{engine: SearchEngine, mode: string, provider: string}|null */
    public function engine(): ?array
    {
        $active = app(OutsideServices::class)->active('search');
        if ($active === null) {
            return null;
        }
        $k = $active['keys'];
        $host = rtrim((string) ($k['host'] ?? ''), '/');
        if ($host === '' || ! str_starts_with($host, 'https://') && ! app()->environment(['local', 'testing'])) {
            return null;
        }
        $engine = match ($active['provider']) {
            'typesense' => new TypesenseEngine($host, (string) ($k['api_key'] ?? '')),
            'meilisearch' => new MeilisearchEngine($host, (string) ($k['api_key'] ?? '')),
            'opensearch' => new OpenSearchEngine($host, (string) ($k['username'] ?? ''), (string) ($k['password'] ?? '')),
            default => null,
        };

        return $engine !== null ? ['engine' => $engine, 'mode' => $active['mode'], 'provider' => $active['provider']] : null;
    }

    /**
     * Brings these listings up to date in the index (added, changed, or removed when no longer shown).
     *
     * @param  list<int>  $ids
     */
    public function sync(array $ids): void
    {
        $e = $this->engine();
        if ($e === null || $ids === []) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Listing::query()->whereIn('id', $chunk)->get();
            $keep = $rows->filter(fn (Listing $l): bool => self::indexable($l));
            $e['engine']->upsert(array_values($keep->map(fn (Listing $l): array => self::document($l))->all()));
            $gone = array_values(array_diff($chunk, $keep->pluck('id')->map(fn ($v): int => (int) $v)->all()));
            $e['engine']->delete($gone);
        }
    }

    /** Rebuilds the whole index. Returns how many listings were sent. */
    public function rebuild(): int
    {
        $e = $this->engine();
        if ($e === null) {
            return 0;
        }
        $e['engine']->prepare();
        $sent = 0;
        Listing::query()->whereNull('off_market_at')->where('internet_display_allowed', true)->chunkById(500, function ($rows) use ($e, &$sent): void {
            $docs = [];
            foreach ($rows as $l) {
                $docs[] = self::document($l);
            }
            $e['engine']->upsert($docs);
            $sent += count($docs);
        });
        Cache::forever(self::COMPARE.':rebuilt', now()->toIso8601String());

        return $sent;
    }

    public static function indexable(Listing $l): bool
    {
        return $l->off_market_at === null && $l->internet_display_allowed;
    }

    /** @return array<string, mixed> */
    public static function document(Listing $l): array
    {
        $text = trim(implode(' ', array_filter([$l->street_number, $l->street_name, $l->unit_number, $l->subdivision, $l->listing_id, $l->city, $l->postal_code],
            fn ($v): bool => is_string($v) && $v !== '')));

        return ['id' => $l->id, 'source' => $l->mls_source_id, 'dup_source' => (int) ($l->duplicate_of_source_id ?? 0), 'status' => (string) $l->standard_status,
            'type' => $l->property_type, 'price' => $l->list_price_cents, 'beds' => $l->beds_total, 'baths' => $l->baths_full, 'sqft' => $l->living_area_sqft,
            'lat' => $l->latitude !== null ? (float) $l->latitude : null, 'lng' => $l->longitude !== null ? (float) $l->longitude : null,
            'text' => $text !== '' ? $text : '-', 'city' => $l->city, 'zip' => $l->postal_code, 'state' => $l->state_or_province, 'agent' => $l->list_agent_mls_id,
            'listed' => ($l->on_market_date ?? $l->created_at)?->getTimestamp() ?? 0, 'changed' => max($l->price_changed_at?->getTimestamp() ?? 0, $l->created_at?->getTimestamp() ?? 0) ?: null,
            'created' => $l->created_at?->getTimestamp() ?? 0, 'show_address' => (bool) $l->address_display_allowed];
    }

    /**
     * Test mode: record how the server's answer compares with the database's.
     *
     * @param  list<int>  $database  first-page ids from the database
     */
    public function compare(array $database, int $databaseTotal, float $databaseMs, callable $ask): void
    {
        try {
            $t = microtime(true);
            $engine = $ask();
            $ms = (microtime(true) - $t) * 1000;
            $overlap = $database === [] ? ($engine['ids'] === [] ? 100 : 0) : (int) round(100 * count(array_intersect($database, $engine['ids'])) / count($database));
            $sample = ['at' => now()->toIso8601String(), 'overlap' => $overlap, 'database_total' => $databaseTotal, 'server_total' => $engine['total'],
                'database_ms' => (int) round($databaseMs), 'server_ms' => (int) round($ms), 'error' => null];
        } catch (Throwable $e) {
            $sample = ['at' => now()->toIso8601String(), 'overlap' => null, 'database_total' => $databaseTotal, 'server_total' => null,
                'database_ms' => (int) round($databaseMs), 'server_ms' => null, 'error' => mb_substr($e->getMessage(), 0, 120)];
        }
        $samples = (array) Cache::get(self::COMPARE, []);
        array_unshift($samples, $sample);
        Cache::forever(self::COMPARE, array_slice($samples, 0, 50));
    }

    /**
     * For HQ: the server, how many listings it holds against how many should be there, and the recent comparisons.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $e = $this->engine();
        $samples = (array) Cache::get(self::COMPARE, []);
        $ok = array_values(array_filter($samples, fn (array $s): bool => $s['overlap'] !== null));
        $avg = fn (string $k): ?int => $ok === [] ? null : (int) round(array_sum(array_column($ok, $k)) / count($ok));
        $count = null;
        if ($e !== null) {
            try {
                $count = $e['engine']->count();
            } catch (Throwable) {
                $count = null;
            }
        }

        return ['provider' => $e['provider'] ?? null, 'mode' => $e['mode'] ?? 'off', 'indexed' => $count,
            'should_be' => Listing::query()->whereNull('off_market_at')->where('internet_display_allowed', true)->count(),
            'rebuilt_at' => Cache::get(self::COMPARE.':rebuilt'), 'compared' => count($samples), 'errors' => count($samples) - count($ok),
            'overlap' => $avg('overlap'), 'database_ms' => $avg('database_ms'), 'server_ms' => $avg('server_ms'), 'samples' => array_slice($samples, 0, 10)];
    }
}
