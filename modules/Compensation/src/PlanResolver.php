<?php

declare(strict_types=1);

namespace Modules\Compensation;

use Carbon\CarbonImmutable;
use Closure;
use Modules\Compensation\Calculator\ResolvedPlan;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Enums\VersionStatus;
use Modules\Compensation\Exceptions\NoPlanForAgent;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\Models\PlanAssignment;
use Modules\Compensation\Models\PlanVersion;

/**
 * The commission plan version in force for an agent on a date: their assignment covering that
 * date, else the default commission plan; within the plan, the latest published version whose
 * effective date is on or before that date.
 */
final class PlanResolver
{
    public function for(int $agentUserId, CarbonImmutable $date): ResolvedPlan
    {
        $assignment = PlanAssignment::query()
            ->where('agent_user_id', $agentUserId)
            ->where('plan_type', PlanType::Commission)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('effective_from')
            ->first();

        $plan = ($assignment !== null ? $assignment->plan : null)
            ?? Plan::query()->where('type', PlanType::Commission)->where('is_default', true)->first()
            ?? throw new NoPlanForAgent("No commission plan for agent {$agentUserId} and no default plan.");

        $version = PlanVersion::query()
            ->where('plan_id', $plan->id)
            ->whereIn('status', [VersionStatus::Published, VersionStatus::Superseded])
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first()
            ?? throw new NoPlanForAgent("Plan \"{$plan->name}\" has no published version in force on {$date->toDateString()}.");

        $rules = $version->commissionRules();
        if ($rules->feeCodes !== []) {
            [$pre, $post] = app(FeeCatalogue::class)->resolve($rules->feeCodes, $date);
            $rules = $rules->withCatalogueFees($pre, $post);
        }

        return new ResolvedPlan($rules, $plan->name, $version->id, $version->version);
    }

    /**
     * @return Closure(int, CarbonImmutable): ResolvedPlan
     */
    public function asClosure(): Closure
    {
        return fn (int $userId, CarbonImmutable $date): ResolvedPlan => $this->for($userId, $date);
    }
}
