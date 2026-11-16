<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

use Modules\Billing\Catalogue;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\Package;

/**
 * The savings comparison (specs/billing/06): what the brokerage pays now, from the prices it entered, against each
 * RightAlly package plus the tools it would keep. Tools RightAlly only partly covers are kept in the total. When the
 * brokerage pays for its agents' software, each agent's RightAlly plan is added at list price; when agents pay their
 * own, the brokerage's share of their plans is shown separately and never mixed into the savings.
 */
final class Savings
{
    public function __construct(private readonly Catalogue $catalogue) {}

    /**
     * @param  list<array{key: string, name: string, monthly_cents: int}>  $tools
     * @return array{now_cents: int, kept_cents: int, replaced: list<array{key: string, name: string, monthly_cents: int, note: string}>, kept: list<array{key: string, name: string, monthly_cents: int, note: string, partly: bool}>,
     *     who_pays: string, agent_plan: array{name: string, list_cents: int, earn_cents: int}|null, options: list<array{package: string, package_cents: int, agents_cents: int, after_cents: int, saves_cents: int, saves_yearly_cents: int}>, best: int|null}
     */
    public function compare(array $tools, int $agents, string $whoPays): array
    {
        $replaced = [];
        $kept = [];
        foreach ($tools as $t) {
            $tool = Tools::find($t['key']) ?? Tools::find('other');
            $row = ['key' => $t['key'], 'name' => $t['name'], 'monthly_cents' => $t['monthly_cents'], 'note' => (string) ($tool['note'] ?? '')];
            if (($tool['rightally'] ?? '') === Tools::REPLACES) {
                $replaced[] = $row;
            } else {
                $kept[] = $row + ['partly' => ($tool['rightally'] ?? '') === Tools::PARTLY];
            }
        }
        $now = array_sum(array_column($tools, 'monthly_cents'));
        $keptCents = array_sum(array_column($kept, 'monthly_cents'));

        $plan = AgentPlan::query()->where('active', true)->orderBy('list_cents')->first();
        $agentPlan = null;
        if ($plan !== null) {
            $floor = $this->catalogue->priceInForce($plan, 'floor');
            $list = $this->catalogue->priceInForce($plan, 'list');
            $commission = (int) floor($floor * (float) $this->catalogue->commissionPercent($agents) / 100);
            $agentPlan = ['name' => $plan->name, 'list_cents' => $list, 'earn_cents' => $commission + max(0, $list - $floor)];
        }
        $agentsCents = $whoPays === 'brokerage' && $agentPlan !== null ? $agentPlan['list_cents'] * $agents : 0;

        $options = array_values(Package::offered('brokerage')->map(function (Package $p) use ($keptCents, $agentsCents, $now): array {
            $after = $keptCents + $p->monthly_cents + $agentsCents;

            return ['package' => $p->name, 'package_cents' => $p->monthly_cents, 'agents_cents' => $agentsCents, 'after_cents' => $after, 'saves_cents' => $now - $after, 'saves_yearly_cents' => ($now - $after) * 12];
        })->all());
        $best = null;
        foreach ($options as $i => $o) {
            if ($best === null || $o['saves_cents'] > $options[$best]['saves_cents']) {
                $best = $i;
            }
        }

        return ['now_cents' => $now, 'kept_cents' => $keptCents, 'replaced' => $replaced, 'kept' => $kept, 'who_pays' => $whoPays, 'agent_plan' => $agentPlan, 'options' => $options, 'best' => $best];
    }
}
