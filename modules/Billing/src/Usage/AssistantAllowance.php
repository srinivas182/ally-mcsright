<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/**
 * AO1: how many website assistant conversations a brokerage may have each month: its package's number, unless HQ
 * set a number for this brokerage alone (more for a busy brokerage, fewer for one running up costs).
 */
final class AssistantAllowance
{
    /** Used when a brokerage has no active package. */
    public const FALLBACK = 500;

    public const MAX = 1_000_000;

    /** @return array{package: int, override: int|null, monthly: int} */
    public static function for(int $tenantId): array
    {
        $db = DB::connection(Connections::control());
        $package = $db->table('billing_tenant_subscriptions')->join('billing_packages', 'billing_packages.id', '=', 'billing_tenant_subscriptions.package_id')
            ->where('billing_tenant_subscriptions.tenant_id', $tenantId)->whereIn('billing_tenant_subscriptions.status', ['active', 'grace'])
            ->orderByDesc('billing_tenant_subscriptions.id')->value('billing_packages.ailly_conversations');
        $package = $package !== null ? (int) $package : self::FALLBACK;
        $override = $db->table('usage_tenant_policies')->where('tenant_id', $tenantId)->value('ailly_monthly');
        $override = $override !== null ? (int) $override : null;

        return ['package' => $package, 'override' => $override, 'monthly' => $override ?? $package];
    }

    /** Sets (or, with null, removes) this brokerage's own number. */
    public static function set(int $tenantId, ?int $monthly, ?int $byUserId): void
    {
        DB::connection(Connections::control())->table('usage_tenant_policies')->updateOrInsert(['tenant_id' => $tenantId],
            ['ailly_monthly' => $monthly !== null ? max(0, min(self::MAX, $monthly)) : null, 'updated_by' => $byUserId, 'updated_at' => now(), 'created_at' => now()]);
    }
}
