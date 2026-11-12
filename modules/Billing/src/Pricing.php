<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\AgentSubscription;
use Modules\Billing\Models\PlanPrice;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * A brokerage's agent prices (specs/core/07): any price between the floor and the public list price.
 * Increases apply to new agents at once and to existing agents after 30 days' notice; decreases
 * apply to everyone at once. The brokerage earns commission on the floor plus 100% of the markup.
 */
final class Pricing
{
    public const AGENT_NOTICE_DAYS = 30;

    public function __construct(private readonly Catalogue $catalogue, private readonly TenantContext $tenants) {}

    public function priceFor(AgentPlan $plan): int
    {
        $row = PlanPrice::query()->where('agent_plan_id', $plan->id)->first();

        return $row !== null ? $row->price_cents : $this->catalogue->priceInForce($plan, 'floor');
    }

    public function offered(AgentPlan $plan): bool
    {
        return PlanPrice::query()->where('agent_plan_id', $plan->id)->value('offered') ?? true;
    }

    public function setPrice(AgentPlan $plan, int $priceCents, bool $offered, User $by): PlanPrice
    {
        $floor = $this->catalogue->priceInForce($plan, 'floor');
        $list = $this->catalogue->priceInForce($plan, 'list');
        if ($priceCents < $floor || $priceCents > $list) {
            throw ValidationException::withMessages(['price' => 'Choose a price from $'.number_format($floor / 100, 2).' (your floor) to $'.number_format($list / 100, 2).' (the public price).']);
        }

        $row = PlanPrice::query()->firstOrNew(['agent_plan_id' => $plan->id]);
        $old = $row->exists ? $row->price_cents : $floor;
        $now = CarbonImmutable::now();
        $row->fill(['price_cents' => $priceCents, 'offered' => $offered]);
        if ($priceCents > $old) {
            $row->fill(['pending_price_cents' => $priceCents, 'pending_from' => $now->addDays(self::AGENT_NOTICE_DAYS)]);
        } elseif ($priceCents < $old) {
            $row->fill(['pending_price_cents' => null, 'pending_from' => null]);
            AgentSubscription::query()->where('agent_plan_id', $plan->id)->where('status', '!=', 'ended')->where('price_cents', '>', $priceCents)
                ->update(['price_cents' => $priceCents]);
        }
        $row->save();
        app(AuditLogger::class)->record('billing.price.changed', metadata: ['plan' => $plan->key, 'old_cents' => $old, 'new_cents' => $priceCents, 'offered' => $offered], severity: 'notice', label: $plan->name);

        return $row;
    }

    public function setDefault(AgentPlan $plan): void
    {
        PlanPrice::query()->update(['is_default' => false]);
        PlanPrice::query()->updateOrCreate(['agent_plan_id' => $plan->id], ['is_default' => true, 'price_cents' => $this->priceFor($plan), 'offered' => true]);
    }

    /**
     * Applies price increases to existing agents once their 30-day notice has passed.
     */
    public function applyDueIncreases(): int
    {
        $count = 0;
        foreach (PlanPrice::query()->whereNotNull('pending_price_cents')->where('pending_from', '<=', CarbonImmutable::now())->get() as $row) {
            $count += AgentSubscription::query()->where('agent_plan_id', $row->agent_plan_id)->where('status', '!=', 'ended')
                ->update(['price_cents' => (int) $row->pending_price_cents]);
            $row->update(['pending_price_cents' => null, 'pending_from' => null]);
        }

        return $count;
    }

    /**
     * What the brokerage earns per paying agent on this plan at this price.
     *
     * @return array{floor_cents: int, commission_percent: string, commission_cents: int, markup_cents: int, total_cents: int, rightally_cents: int}
     */
    public function earningsPreview(AgentPlan $plan, int $priceCents): array
    {
        $floor = $this->catalogue->priceInForce($plan, 'floor');
        $percent = $this->catalogue->commissionPercent($this->payingAgents());
        $commission = (int) floor($floor * (float) $percent / 100);
        $markup = max(0, $priceCents - $floor);

        return ['floor_cents' => $floor, 'commission_percent' => $percent, 'commission_cents' => $commission, 'markup_cents' => $markup,
            'total_cents' => $commission + $markup, 'rightally_cents' => $floor - $commission];
    }

    public function payingAgents(): int
    {
        return AgentSubscription::query()->whereIn('status', ['active', 'grace'])->count();
    }

    public function configure(string $model, string $noPlanPolicy, User $by): void
    {
        if (! in_array($model, ['brokerage_pays', 'agent_pays'], true) || ! in_array($noPlanPolicy, ['minimal', 'blocked'], true)) {
            throw ValidationException::withMessages(['model' => 'Choose who pays and what agents without a plan can use.']);
        }
        Tenant::query()->whereKey($this->tenants->id())->update(['billing_model' => $model, 'no_plan_policy' => $noPlanPolicy]);
        $this->tenants->set($this->tenants->current()->fresh() ?? $this->tenants->current());
        app(AuditLogger::class)->record('billing.model.changed', metadata: ['model' => $model, 'no_plan_policy' => $noPlanPolicy], severity: 'warning');
    }
}
