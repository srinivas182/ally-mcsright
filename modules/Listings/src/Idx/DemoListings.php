<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Samples\SampleHomes;

/**
 * IDX5: demo listings. A brokerage that has added an MLS board but has none live yet shows sample listings on its
 * websites (from the feeds HQ marks as demo, such as Repliers' sample data), each clearly marked as a sample and never
 * indexed by search engines. The first board to go live replaces them. The brokerage can switch them off.
 * TP1: a brokerage with no board yet shows RightAlly's sample homes (SampleHomes) in its state, so every new website
 * has homes from day one.
 */
final class DemoListings
{
    /**
     * Demo feeds this brokerage shows now: none if it has switched them off, has no board, or has a board live.
     *
     * @return list<int>
     */
    public function sourcesFor(int $tenantId): array
    {
        return Cache::remember("idx-demo:{$tenantId}", 60, function () use ($tenantId): array {
            $db = DB::connection(Connections::control());
            if (! $this->enabled($tenantId)) {
                return [];
            }
            $subs = $db->table('idx_subscriptions')->join('idx_boards', 'idx_boards.id', '=', 'idx_subscriptions.board_id')
                ->where('idx_subscriptions.tenant_id', $tenantId)->where('idx_subscriptions.status', '!=', 'cancelled')->get(['idx_subscriptions.status', 'idx_boards.state']);
            if ($subs->contains('status', 'live')) {
                return []; // the brokerage's own MLS listings show instead
            }
            $sources = [];
            if ($subs->isNotEmpty()) { // HQ's demo feeds (such as Repliers' sample data) for brokerages waiting on a board
                $sources = MlsSource::query()->where('is_demo', true)->where('status', 'active')->where('key', 'not like', SampleHomes::SOURCE_PREFIX.'%')
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            }
            if (SampleHomes::enabled()) {
                // TP1: sample homes in the brokerage's own state(s): its boards' states, else the state it signed up in, else every state.
                $states = $subs->pluck('state')->filter()->unique()->values()->all();
                if ($states === []) {
                    $signupState = $db->table('signups')->where('tenant_id', $tenantId)->orderByDesc('id')->value('state');
                    $states = is_string($signupState) && $signupState !== '' ? [$signupState] : [];
                }
                $samples = [];
                foreach ($states as $st) {
                    $samples = array_merge($samples, SampleHomes::sourceIds((string) $st));
                }
                $sources = array_merge($sources, $samples !== [] ? $samples : SampleHomes::sourceIds());
            }

            return array_values(array_unique($sources));
        });
    }

    public function enabled(int $tenantId): bool
    {
        $v = DB::connection(Connections::control())->table('idx_tenant_settings')->where('tenant_id', $tenantId)->value('demo_listings');

        return $v === null || (bool) $v;
    }

    public function set(int $tenantId, bool $on, User $by): void
    {
        DB::connection(Connections::control())->table('idx_tenant_settings')->updateOrInsert(['tenant_id' => $tenantId],
            ['demo_listings' => $on, 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        self::forget($tenantId);
        app(AuditLogger::class)->record('idx.demo.'.($on ? 'on' : 'off'), severity: 'notice', tenantId: $tenantId);
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget("idx-demo:{$tenantId}");
        Cache::forget("mls-licences:{$tenantId}");
    }
}
