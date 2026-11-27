<?php

declare(strict_types=1);

namespace Modules\Compensation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Plan;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Events\TenantProvisioned;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * AG5: a new brokerage starts with the two plans most agents ask about, published, offered when agents join, and
 * revenue share as the default. Both are ordinary plans: the owner changes, replaces or archives them under
 * Commissions › Plans. Money defaults (flagged to the owner on the plans page):
 * - Revenue share: agent 90 / brokerage 10, cap $16,000 a year from the agent's join anniversary, then $500 a deal;
 *   revenue share on 5 levels from a pool of half the brokerage's company dollar, split 40 / 20 / 20 / 10 / 10.
 * - Flat fee: $500 a deal, no cap.
 */
final class StarterPlans
{
    public const SHARE = 'Revenue share';

    public const FLAT = 'Flat fee';

    public function __construct(private readonly ManagePlans $plans, private readonly TenantContext $tenants) {}

    /** New brokerages only (not HQ or other tenant types). */
    public function onProvisioned(TenantProvisioned $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);
        if ($tenant === null || $tenant->type !== TenantType::Brokerage) {
            return;
        }
        $this->tenants->runAs($tenant, fn () => $this->seed());
    }

    /**
     * Adds the two plans when the brokerage has no commission plans yet. Returns whether it did.
     */
    public function seed(?int $byUserId = null): bool
    {
        if (Plan::query()->where('type', PlanType::Commission)->exists()) {
            return false;
        }
        DB::transaction(function () use ($byUserId): void {
            $from = CarbonImmutable::today();
            $share = $this->plans->create(PlanType::Commission, self::SHARE, self::shareRules(), $byUserId,
                'Grow with the team: keep 90% until you cap, then almost everything, and earn from the agents you bring in.');
            $this->plans->publish($share->versions()->sole(), $from, $byUserId, 'Starter plan');
            $flat = $this->plans->create(PlanType::Commission, self::FLAT, self::flatRules(), $byUserId, 'Simple and predictable: one fee per deal, the rest is yours.');
            $this->plans->publish($flat->versions()->sole(), $from, $byUserId, 'Starter plan');
            $this->plans->setDefault($share->fresh() ?? $share);
            $this->plans->offerOnJoin($share->fresh() ?? $share, true);
            $this->plans->offerOnJoin($flat->fresh() ?? $flat, true);
        });
        app(AuditLogger::class)->record('compensation.starter_plans.added', metadata: ['plans' => [self::SHARE, self::FLAT]], severity: 'notice');

        return true;
    }

    /** @return array<string, mixed> */
    public static function shareRules(): array
    {
        return ['split' => ['mode' => 'percentage', 'agent_percent' => '90'],
            'cap' => ['amount_cents' => 1_600_000, 'anchor' => 'anniversary'],
            'post_cap' => ['fee' => ['calc' => 'flat', 'amount' => 50_000]],
            'revenue_share' => ['basis' => 'company_dollar', 'pool_percent' => '50', 'levels' => ['40', '20', '20', '10', '10']]];
    }

    /** @return array<string, mixed> */
    public static function flatRules(): array
    {
        return ['split' => ['mode' => 'flat_fee', 'bands' => [['up_to_price_cents' => null, 'fee_cents' => 50_000]]]];
    }
}
