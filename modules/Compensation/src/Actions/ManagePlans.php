<?php

declare(strict_types=1);

namespace Modules\Compensation\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Enums\PlanStatus;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Enums\VersionStatus;
use Modules\Compensation\Events\PlansChanged;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\FeeCatalogue;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\Models\PlanAssignment;
use Modules\Compensation\Models\PlanVersion;
use Modules\Compensation\Rules\CommissionRules;

/**
 * Plans, versions, the default plan and assignments (specs/compensation/01; legacy rules 1–5).
 */
final class ManagePlans
{
    /**
     * Creates the plan with a first draft version.
     *
     * @param  array<string, mixed>  $rules
     */
    public function create(PlanType $type, string $name, array $rules, ?int $byUserId = null, ?string $description = null): Plan
    {
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'Give the plan a name.']);
        }

        $this->validateRules($type, $rules);

        return DB::transaction(function () use ($type, $name, $rules, $byUserId, $description): Plan {
            $plan = Plan::query()->create([
                'type' => $type, 'name' => trim($name), 'description' => $description,
                'status' => PlanStatus::Active, 'created_by' => $byUserId,
            ]);
            $plan->versions()->create(['version' => 1, 'status' => VersionStatus::Draft, 'rules' => $rules]);

            return $plan;
        });
    }

    /**
     * Updates the open draft, or starts a new draft from the latest version.
     *
     * @param  array<string, mixed>  $rules
     */
    public function saveDraft(Plan $plan, array $rules): PlanVersion
    {
        $this->validateRules($plan->type, $rules);

        $draft = $plan->versions()->where('status', VersionStatus::Draft)->first();
        if ($draft !== null) {
            $draft->update(['rules' => $rules]);

            return $draft;
        }

        $next = (int) $plan->versions()->max('version') + 1;

        return $plan->versions()->create(['version' => $next, 'status' => VersionStatus::Draft, 'rules' => $rules]);
    }

    public function publish(PlanVersion $draft, CarbonImmutable $effectiveFrom, ?int $byUserId = null, ?string $note = null): PlanVersion
    {
        $published = $this->publishVersion($draft, $effectiveFrom, $byUserId, $note);
        PlansChanged::dispatch(null, "Plan \"{$published->plan->name}\" version {$published->version} published");

        return $published;
    }

    private function publishVersion(PlanVersion $draft, CarbonImmutable $effectiveFrom, ?int $byUserId, ?string $note): PlanVersion
    {
        if ($draft->status !== VersionStatus::Draft) {
            throw ValidationException::withMessages(['version' => 'Only a draft can be published.']);
        }

        $this->validateRules($draft->plan->type, $draft->rules);

        // Every catalogue fee the plan uses must exist on its effective date.
        $codes = (array) ($draft->rules['fees'] ?? []);
        if ($codes !== []) {
            app(FeeCatalogue::class)->resolve(array_values(array_map('strval', $codes)), $effectiveFrom);
        }

        return DB::transaction(function () use ($draft, $effectiveFrom, $byUserId, $note): PlanVersion {
            $latest = PlanVersion::query()->where('plan_id', $draft->plan_id)->where('status', VersionStatus::Published)
                ->lockForUpdate()->orderByDesc('version')->first();

            if ($latest !== null && $latest->effective_from !== null && $effectiveFrom->lt($latest->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'A new version cannot start before version '.$latest->version.' ('.$latest->effective_from->toDateString().').']);
            }

            $latest?->update(['status' => VersionStatus::Superseded]);

            $draft->update([
                'status' => VersionStatus::Published,
                'effective_from' => $effectiveFrom->startOfDay(),
                'published_by' => $byUserId,
                'published_at' => CarbonImmutable::now(),
                'change_note' => $note,
            ]);

            return $draft;
        });
    }

    /**
     * Legacy rule 2: only commission plans can be the default; exactly one default at a time.
     */
    public function setDefault(Plan $plan): Plan
    {
        if ($plan->type !== PlanType::Commission) {
            throw ValidationException::withMessages(['plan' => 'Only a commission plan can be the default.']);
        }
        if ($plan->status !== PlanStatus::Active || $plan->latestPublished() === null) {
            throw ValidationException::withMessages(['plan' => 'Publish the plan before making it the default.']);
        }

        DB::transaction(function () use ($plan): void {
            Plan::query()->where('type', PlanType::Commission)->where('is_default', true)->whereKeyNot($plan->id)->update(['is_default' => false]);
            $plan->update(['is_default' => true]);
        });
        PlansChanged::dispatch(null, "Default plan changed to \"{$plan->name}\"");

        return $plan;
    }

    /**
     * AG4: lets new agents pick this plan when they join online (or stops it). Only a published, active commission
     * plan can be offered; agents already on it stay on it either way.
     */
    public function offerOnJoin(Plan $plan, bool $offered): Plan
    {
        if ($offered && ($plan->type !== PlanType::Commission || $plan->status !== PlanStatus::Active || $plan->latestPublished() === null)) {
            throw ValidationException::withMessages(['plan' => 'Publish the plan before offering it to new agents.']);
        }
        $plan->update(['offered_on_join' => $offered]);

        return $plan;
    }

    public function archive(Plan $plan): Plan
    {
        if ($plan->is_default) {
            throw ValidationException::withMessages(['plan' => 'Choose another default plan before archiving this one.']);
        }

        $inUse = PlanAssignment::query()->where('plan_id', $plan->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', CarbonImmutable::today()->toDateString()))
            ->exists();
        if ($inUse) {
            throw ValidationException::withMessages(['plan' => 'Agents are still on this plan. Move them to another plan first.']);
        }

        $plan->update(['status' => PlanStatus::Archived, 'offered_on_join' => false]);

        return $plan;
    }

    /**
     * Puts an agent on a plan from a date; an open earlier assignment of the same type ends the
     * day before. Overlapping future assignments are refused.
     */
    public function assign(int $agentUserId, Plan $plan, CarbonImmutable $from, ?int $byUserId = null): PlanAssignment
    {
        $assignment = $this->assignAgent($agentUserId, $plan, $from, $byUserId);
        PlansChanged::dispatch([$agentUserId], "Agent moved to plan \"{$plan->name}\"");

        return $assignment;
    }

    private function assignAgent(int $agentUserId, Plan $plan, CarbonImmutable $from, ?int $byUserId): PlanAssignment
    {
        if ($plan->status !== PlanStatus::Active) {
            throw ValidationException::withMessages(['plan' => 'This plan is archived.']);
        }

        return DB::transaction(function () use ($agentUserId, $plan, $from, $byUserId): PlanAssignment {
            $from = $from->startOfDay();
            $later = PlanAssignment::query()->where('agent_user_id', $agentUserId)->where('plan_type', $plan->type)
                ->whereDate('effective_from', '>=', $from->toDateString())->exists();
            if ($later) {
                throw ValidationException::withMessages(['effective_from' => 'This agent already has a plan starting on or after that date.']);
            }

            PlanAssignment::query()->where('agent_user_id', $agentUserId)->where('plan_type', $plan->type)
                ->whereNull('effective_to')->update(['effective_to' => $from->subDay()->toDateString()]);

            return PlanAssignment::query()->create([
                'agent_user_id' => $agentUserId, 'plan_id' => $plan->id, 'plan_type' => $plan->type,
                'effective_from' => $from, 'assigned_by' => $byUserId,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function validateRules(PlanType $type, array $rules): void
    {
        // CM1: onboarding plans are gone; joining, monthly and annual fees are set up under Fees.
        if ($type !== PlanType::Commission) {
            throw ValidationException::withMessages(['type' => ['Plans are commission plans. Set up joining and monthly fees under Commissions › Fees.']]);
        }
        try {
            CommissionRules::fromArray($rules);
        } catch (InvalidRules $e) {
            throw ValidationException::withMessages(array_map(fn (string $m): array => [$m], $e->errors));
        }
    }
}
