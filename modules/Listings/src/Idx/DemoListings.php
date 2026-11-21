<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Listings\Models\MlsSource;

/**
 * IDX5: demo listings. A brokerage that has added an MLS board but has none live yet shows sample listings on its
 * websites (from the feeds HQ marks as demo, such as Repliers' sample data), each clearly marked as a sample and never
 * indexed by search engines. The first board to go live replaces them. The brokerage can switch them off.
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
            $boards = $db->table('idx_subscriptions')->where('tenant_id', $tenantId)->where('status', '!=', 'cancelled')->pluck('status')->all();
            if ($boards === [] || in_array('live', $boards, true)) {
                return [];
            }

            return array_values(MlsSource::query()->where('is_demo', true)->where('status', 'active')->pluck('id')->map(fn ($id): int => (int) $id)->all());
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
