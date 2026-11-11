<?php

declare(strict_types=1);

namespace Modules\Compensation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Calculator\AgentCalculation;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\CommissionCalculator;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\Calculator\ResolvedPlan;
use Modules\Compensation\Calculator\SideCalculation;
use Modules\Compensation\Events\AgentCapped;
use Modules\Compensation\Events\CalculationFinalised;
use Modules\Compensation\Events\CalculationReversed;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Models\CalculationAgent;
use Modules\Compensation\Models\CapEntry;
use Modules\Compensation\Models\CapPeriod;
use Modules\Ledger\Money\Money;

/**
 * Saved commission calculations with cap tracking (specs/compensation/01, Sprint 4B).
 *
 * - calculate(): a pending calculation for a deal side using each agent's cap progress now
 * - finalise(): recalculates against the latest cap progress, records cap entries, then
 *   recalculates every other pending deal of those agents (automatic, with a "what changed" note)
 * - reverse(): negative cap entries; pending deals recalculated again
 * Calculations are versioned per deal side and never overwritten.
 */
final class CommissionService
{
    public function __construct(
        private readonly CommissionCalculator $calculator,
        private readonly PlanResolver $plans,
        private readonly CapPeriods $periods,
    ) {}

    public function calculate(DealSideInput $input, string $dealRef, ?int $byUserId = null, ?string $note = null): Calculation
    {
        return DB::transaction(function () use ($input, $dealRef, $byUserId, $note): Calculation {
            $existing = Calculation::query()->where('deal_ref', $dealRef)->where('side', $input->side->value)->orderByDesc('version')->first();
            if ($existing !== null && $existing->status === 'final') {
                throw ValidationException::withMessages(['deal' => 'This deal side is final. Reverse it before recalculating.']);
            }

            return $this->store($input, $dealRef, 'pending', $existing, $byUserId, $note);
        });
    }

    public function finalise(Calculation $pending, ?int $byUserId = null): Calculation
    {
        if ($pending->status !== 'pending') {
            throw ValidationException::withMessages(['calculation' => 'Only a pending calculation can be finalised.']);
        }

        $input = DealSideInput::fromArray($pending->inputs);

        $final = DB::transaction(function () use ($pending, $input, $byUserId): Calculation {
            // Lock the agents' cap periods so concurrent closings cannot both use the same cap room.
            foreach ($input->agents as $agent) {
                CapPeriod::query()->where('agent_user_id', $agent->userId)->lockForUpdate()->get();
            }

            $final = $this->store($input, $pending->deal_ref, 'final', $pending, $byUserId, 'Finalised');

            foreach ((array) $final->result['agents'] as $row) {
                /** @var array<string, mixed> $row */
                $this->addEntry($final, $row, 1, 'deal');
            }

            return $final;
        });

        CalculationFinalised::dispatch($final->tenant_id, $final->ulid);
        $this->recalculatePending(array_map(fn (AgentShare $a): int => $a->userId, $input->agents), 'Cap progress changed after deal '.$final->deal_ref.' closed', $final->deal_ref);

        return $final;
    }

    public function reverse(Calculation $final, string $reason, ?int $byUserId = null): Calculation
    {
        if ($final->status !== 'final') {
            throw ValidationException::withMessages(['calculation' => 'Only a final calculation can be reversed.']);
        }

        DB::transaction(function () use ($final, $reason): void {
            foreach ((array) $final->result['agents'] as $row) {
                /** @var array<string, mixed> $row */
                $this->addEntry($final, $row, -1, 'reversal');
            }
            $final->update(['status' => 'reversed', 'change_note' => mb_substr('Reversed: '.$reason, 0, 500)]);
            CalculationAgent::query()->where('calculation_id', $final->id)->update(['status' => 'reversed']);
        });

        CalculationReversed::dispatch($final->tenant_id, $final->ulid);
        $input = DealSideInput::fromArray($final->inputs);
        $this->recalculatePending(array_map(fn (AgentShare $a): int => $a->userId, $input->agents), 'Cap progress changed after deal '.$final->deal_ref.' was reversed');

        return $final;
    }

    /**
     * Recalculates every pending deal of these agents (all agents when null). A new version is
     * stored only when an amount changed, with a note saying what changed.
     *
     * @param  list<int>|null  $agentUserIds
     */
    public function recalculatePending(?array $agentUserIds, string $reason, ?string $exceptDealRef = null): int
    {
        $ids = CalculationAgent::query()->where('status', 'pending')
            ->when($agentUserIds !== null, fn ($q) => $q->whereIn('agent_user_id', $agentUserIds))
            ->distinct()->pluck('calculation_id');

        $changed = 0;
        foreach (Calculation::query()->whereIn('id', $ids)->where('status', 'pending')->get() as $pending) {
            if ($pending->deal_ref === $exceptDealRef) {
                continue;
            }

            $input = DealSideInput::fromArray($pending->inputs);
            $fresh = $this->run($input);
            $differences = $this->differences($pending->result, $fresh);

            if ($differences !== []) {
                DB::transaction(fn () => $this->store($input, $pending->deal_ref, 'pending', $pending, null, $reason.': '.implode('; ', $differences)));
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Nightly check: every cap period's totals equal the sum of its entries.
     *
     * @return list<string> problems (empty when everything reconciles)
     */
    public function checkCapPeriods(): array
    {
        $problems = [];
        foreach (CapPeriod::query()->get() as $period) {
            $company = (int) CapEntry::query()->where('cap_period_id', $period->id)->sum('company_dollar_cents');
            $fees = (int) CapEntry::query()->where('cap_period_id', $period->id)->sum('post_cap_fee_cents');
            if ($company !== $period->company_dollar_cents || $fees !== $period->post_cap_fees_cents) {
                $problems[] = "Cap period {$period->ulid}: totals {$period->company_dollar_cents}/{$period->post_cap_fees_cents}, entries {$company}/{$fees}.";
            }
        }

        return $problems;
    }

    private function run(DealSideInput $input): SideCalculation
    {
        $resolved = [];
        $agents = [];
        foreach ($input->agents as $agent) {
            $plan = $resolved[$agent->userId] = $this->plans->for($agent->userId, $input->closingDate);
            $agents[] = $this->withCapState($agent, $plan, $input->closingDate);
        }

        $stateful = new DealSideInput($input->gci, $input->salePriceCents, $input->closingDate, $agents, $input->side,
            $input->dealClass, $input->leadSource, $input->outsideReferralPercent);

        return $this->calculator->calculate($stateful, fn (int $userId): ResolvedPlan => $resolved[$userId]);
    }

    private function withCapState(AgentShare $agent, ResolvedPlan $plan, CarbonImmutable $date): AgentShare
    {
        $cap = $plan->rules->cap;
        if ($cap === null) {
            return $agent;
        }

        $period = $this->periods->periodFor($agent->userId, $cap, $date);
        $units = CapEntry::query()->where('cap_period_id', $period->id)->where('reason', 'deal')->count()
            - CapEntry::query()->where('cap_period_id', $period->id)->where('reason', 'reversal')->count();

        return new AgentShare($agent->userId, $agent->sharePercent, $period->company_dollar_cents, max(0, $units),
            $period->company_dollar_cents, $period->post_cap_fees_cents);
    }

    private function store(DealSideInput $input, string $dealRef, string $status, ?Calculation $previous, ?int $byUserId, ?string $note): Calculation
    {
        $result = $this->run($input);

        if ($previous !== null && in_array($previous->status, ['pending'], true)) {
            $previous->update(['status' => 'superseded']);
            CalculationAgent::query()->where('calculation_id', $previous->id)->update(['status' => 'superseded']);
        }

        $version = (int) Calculation::query()->where('deal_ref', $dealRef)->where('side', $input->side->value)->max('version') + 1;
        $calculation = Calculation::query()->create([
            'deal_ref' => $dealRef,
            'side' => $input->side->value,
            'version' => $version,
            'status' => $status,
            'inputs' => $input->toArray(),
            'result' => $this->serialise($result, $input->closingDate),
            'steps' => $result->explanation(),
            'change_note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'created_by' => $byUserId,
        ]);

        foreach ($input->agents as $agent) {
            CalculationAgent::query()->create(['calculation_id' => $calculation->id, 'agent_user_id' => $agent->userId, 'status' => $status]);
        }

        return $calculation;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function addEntry(Calculation $calculation, array $row, int $sign, string $reason): void
    {
        $periodId = $row['cap_period_id'] ?? null;
        if ($periodId === null) {
            return; // no cap on this agent's plan
        }

        $period = CapPeriod::query()->whereKey((int) $periodId)->lockForUpdate()->firstOrFail();
        $company = $sign * (int) $row['company_cents'];
        $fee = $sign * (int) ($row['post_cap_fee_cents'] ?? 0);

        CapEntry::query()->create(['cap_period_id' => $period->id, 'calculation_id' => $calculation->id,
            'company_dollar_cents' => $company, 'post_cap_fee_cents' => $fee, 'reason' => $reason]);

        $total = $period->company_dollar_cents + $company;
        $wasCapped = $period->status === 'capped';
        $nowCapped = $period->cap_amount_cents !== null && $total >= $period->cap_amount_cents;
        $period->update([
            'company_dollar_cents' => $total,
            'post_cap_fees_cents' => $period->post_cap_fees_cents + $fee,
            'status' => $nowCapped ? 'capped' : 'open',
        ]);

        if ($nowCapped && ! $wasCapped) {
            AgentCapped::dispatch($period->tenant_id, $period->agent_user_id, $period->ulid);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(SideCalculation $result, CarbonImmutable $date): array
    {
        return [
            'gci_cents' => $result->gci->cents,
            'outside_referral_cents' => $result->outsideReferral->cents,
            'agents' => array_map(function (AgentCalculation $a) use ($date): array {
                $period = $a->capAmountCents !== null
                    ? CapPeriod::query()->where('agent_user_id', $a->userId)->whereDate('starts_on', '<=', $date->toDateString())
                        ->whereDate('ends_on', '>=', $date->toDateString())->value('id')
                    : null;

                return [
                    'user_id' => $a->userId,
                    'plan' => $a->planName,
                    'plan_version_id' => $a->planVersionId,
                    'share_cents' => $a->share->cents,
                    'pre_split_cents' => $a->preSplitTotal()->cents,
                    'company_cents' => $a->companyDollar->cents,
                    'post_cap_fee_cents' => $a->postCapFee !== null ? $a->postCapFee->cents : 0,
                    'post_split_cents' => $a->postSplitTotal()->cents,
                    'agent_net_cents' => $a->agentNet->cents,
                    'cap_amount_cents' => $a->capAmountCents,
                    'cap_before_cents' => $a->capBeforeCents,
                    'cap_after_cents' => $a->capAfterCents,
                    'capped' => $a->capped,
                    'cap_period_id' => $period,
                ];
            }, $result->agents),
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @return list<string>
     */
    private function differences(array $old, SideCalculation $fresh): array
    {
        $out = [];
        $before = [];
        foreach ((array) ($old['agents'] ?? []) as $row) {
            /** @var array<string, mixed> $row */
            $before[(int) $row['user_id']] = $row;
        }

        foreach ($fresh->agents as $agent) {
            $was = $before[$agent->userId] ?? null;
            if ($was === null) {
                continue;
            }
            foreach (['company_cents' => [$agent->companyDollar->cents, 'company dollar'], 'agent_net_cents' => [$agent->agentNet->cents, 'agent receives'], 'post_cap_fee_cents' => [$agent->postCapFee !== null ? $agent->postCapFee->cents : 0, 'post-cap fee']] as $key => [$now, $label]) {
                if ((int) ($was[$key] ?? 0) !== $now) {
                    $out[] = "agent {$agent->userId} {$label} ".Money::of((int) ($was[$key] ?? 0))->format().' → '.Money::of($now)->format();
                }
            }
        }

        return $out;
    }
}
