<?php

declare(strict_types=1);

namespace Modules\Compensation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\CommissionCalculator;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\Calculator\ResolvedPlan;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Models\CalculationAgent;
use Modules\Compensation\Models\CapPeriod;
use Modules\Compensation\Rules\CommissionRules;
use Throwable;

/**
 * "My plan" for agents (distance to cap, take-home on pending deals, forecast) and, for brokers,
 * company dollar by plan and agent, plus the what-if: last 12 months of closed deals replayed
 * through a proposed plan (approved additions 10 and 11, and the earnings forecast).
 */
final class Insights
{
    public function __construct(private readonly PlanResolver $plans, private readonly CommissionCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function myPlan(User $agent): array
    {
        $now = CarbonImmutable::now();
        try {
            $resolved = $this->plans->for($agent->id, $now);
        } catch (Throwable) {
            return ['plan' => null];
        }

        $period = CapPeriod::query()->where('agent_user_id', $agent->id)->whereDate('starts_on', '<=', $now->toDateString())
            ->whereDate('ends_on', '>=', $now->toDateString())->first();
        $cap = $resolved->rules->cap?->amountCents;
        $paid = $period !== null ? $period->company_dollar_cents : 0;

        $forecast = null;
        if ($cap !== null && $period !== null && $paid < $cap && $paid > 0) {
            $days = max(1, (int) $period->starts_on->diffInDays($now));
            $perDay = $paid / $days;
            $capDate = $now->addDays((int) ceil(($cap - $paid) / $perDay));
            $forecast = $capDate->lte($period->ends_on)
                ? "At your current pace you'll cap in ".$capDate->format('F').'.'
                : 'At your current pace you won\'t reach the cap this year; '.self::money($cap - $paid).' to go.';
        }

        $pendingIds = CalculationAgent::query()->where('agent_user_id', $agent->id)->where('status', 'pending')->pluck('calculation_id');
        $pending = 0;
        $deals = 0;
        foreach (Calculation::query()->whereIn('id', $pendingIds)->get() as $calc) {
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                if ((int) $row['user_id'] === $agent->id) {
                    $pending += (int) $row['agent_net_cents'];
                    $deals++;
                }
            }
        }

        return [
            'plan' => $resolved->planName,
            'cap_cents' => $cap,
            'paid_cents' => $paid,
            'to_cap_cents' => $cap !== null ? max(0, $cap - $paid) : null,
            'capped' => $cap !== null && $paid >= $cap,
            'period' => $period !== null ? ['starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString()] : null,
            'pending_deals' => $deals,
            'pending_take_home_cents' => $pending,
            'forecast' => $forecast,
        ];
    }

    /**
     * Company dollar by plan and by agent from finalised deals in a date range.
     *
     * @return array{plans: list<array<string, mixed>>, agents: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function profitability(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, array{key: string, deals: int, gci_cents: int, company_cents: int, net_company_cents: int}> $byPlan */
        $byPlan = [];
        /** @var array<string, array{key: string, deals: int, gci_cents: int, company_cents: int, net_company_cents: int}> $byAgent */
        $byAgent = [];
        $totals = ['deals' => 0, 'gci_cents' => 0, 'company_cents' => 0, 'net_company_cents' => 0];
        $add = static function (array $bucket, string $key, int $gci, int $gross, int $net): array {
            $row = $bucket[$key] ?? ['key' => $key, 'deals' => 0, 'gci_cents' => 0, 'company_cents' => 0, 'net_company_cents' => 0];
            $row['deals']++;
            $row['gci_cents'] += $gci;
            $row['company_cents'] += $gross;
            $row['net_company_cents'] += $net;
            $bucket[$key] = $row;

            return $bucket;
        };
        foreach ($this->finals($from, $to) as $calc) {
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                $gross = (int) $row['company_cents'] + (int) ($row['post_cap_fee_cents'] ?? 0);
                $net = $gross - (int) ($row['brokerage_fees_cents'] ?? 0) - (int) ($row['credit_used_cents'] ?? 0);
                $gci = (int) $row['share_cents'];
                $byPlan = $add($byPlan, (string) $row['plan'], $gci, $gross, $net);
                $byAgent = $add($byAgent, (string) $row['user_id'], $gci, $gross, $net);
                $totals['deals']++;
                $totals['gci_cents'] += $gci;
                $totals['company_cents'] += $gross;
                $totals['net_company_cents'] += $net;
            }
        }
        $names = User::query()->whereIn('id', array_keys($byAgent))->get()->mapWithKeys(fn (User $u): array => [(string) $u->id => $u->name]);
        $agents = array_values(array_map(fn (array $r): array => $r + ['name' => $names[$r['key']] ?? 'Former agent'], $byAgent));
        usort($agents, fn ($a, $b) => $b['net_company_cents'] <=> $a['net_company_cents']);

        return ['plans' => array_values($byPlan), 'agents' => $agents, 'totals' => $totals];
    }

    /**
     * Replays closed deals from the last 12 months through proposed rules, deal by deal, with each
     * agent's cap progressing as it would have. Nothing is saved.
     *
     * @param  array<string, mixed>  $proposedRules
     * @return array{agents: list<array<string, mixed>>, actual_company_cents: int, proposed_company_cents: int, deals: int}
     */
    public function whatIf(array $proposedRules, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rules = CommissionRules::fromArray($proposedRules);
        $catalogue = app(FeeCatalogue::class);
        $capPaid = [];
        /** @var array<int, array{user_id: int, deals: int, actual_company_cents: int, proposed_company_cents: int, actual_agent_cents: int, proposed_agent_cents: int}> $rows */
        $rows = [];
        $actual = 0;
        $proposed = 0;
        $deals = 0;

        $finals = $this->finals($now->subYear(), $now)->sortBy(fn (Calculation $c) => (string) ($c->inputs['closing_date'] ?? ''));
        foreach ($finals as $calc) {
            $input = DealSideInput::fromArray($calc->inputs);
            [$pre, $post] = $catalogue->resolve($rules->feeCodes, $input->closingDate);
            $plan = new ResolvedPlan($rules->withCatalogueFees($pre, $post), 'Proposed');
            $agents = array_map(fn (AgentShare $a): AgentShare => new AgentShare($a->userId, $a->sharePercent, $capPaid[$a->userId] ?? 0, 0, $capPaid[$a->userId] ?? 0), $input->agents);
            $result = $this->calculator->calculate(new DealSideInput($input->gci, $input->salePriceCents, $input->closingDate, $agents, $input->side,
                $input->dealClass, $input->leadSource, $input->outsideReferralPercent, $input->excludedReason), fn (): ResolvedPlan => $plan);

            foreach ($result->agents as $a) {
                $capPaid[$a->userId] = $a->capAfterCents > 0 ? $a->capAfterCents : ($capPaid[$a->userId] ?? 0);
                $rows[$a->userId] ??= self::blank($a->userId);
                $rows[$a->userId]['deals']++;
                $rows[$a->userId]['proposed_company_cents'] += $a->companyDollar->cents + ($a->postCapFee !== null ? $a->postCapFee->cents : 0);
                $rows[$a->userId]['proposed_agent_cents'] += $a->agentNet->cents;
                $proposed += $a->companyDollar->cents + ($a->postCapFee !== null ? $a->postCapFee->cents : 0);
            }
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                $uid = (int) $row['user_id'];
                $rows[$uid] ??= self::blank($uid);
                $rows[$uid]['actual_company_cents'] += (int) $row['company_cents'] + (int) ($row['post_cap_fee_cents'] ?? 0);
                $rows[$uid]['actual_agent_cents'] += (int) $row['agent_net_cents'];
                $actual += (int) $row['company_cents'] + (int) ($row['post_cap_fee_cents'] ?? 0);
            }
            $deals++;
        }

        $names = User::query()->whereIn('id', array_keys($rows))->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name]);

        return [
            'agents' => array_values(array_map(fn (array $r): array => $r + ['name' => $names[$r['user_id']] ?? 'Former agent'], $rows)),
            'actual_company_cents' => $actual, 'proposed_company_cents' => $proposed, 'deals' => $deals,
        ];
    }

    /**
     * @return Collection<int, Calculation>
     */
    private function finals(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Calculation::query()->where('status', 'final')->get()
            ->filter(fn (Calculation $c): bool => ($d = (string) ($c->inputs['closing_date'] ?? '')) >= $from->toDateString() && $d <= $to->toDateString())->values();
    }

    /**
     * @return array{user_id: int, deals: int, actual_company_cents: int, proposed_company_cents: int, actual_agent_cents: int, proposed_agent_cents: int}
     */
    private static function blank(int $userId): array
    {
        return ['user_id' => $userId, 'deals' => 0, 'actual_company_cents' => 0, 'proposed_company_cents' => 0, 'actual_agent_cents' => 0, 'proposed_agent_cents' => 0];
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 0);
    }
}
