<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Compensation\Models\Calculation;
use Modules\Transactions\Models\Deal;

/**
 * Pending-deal cash forecast (specs/transactions/01, addition 12): company dollar expected from deals
 * under contract, by expected closing date (30, 60, 90 days), from each deal's commission calculation.
 */
final class Forecast
{
    /**
     * @return array{buckets: array<int, array{days: int, deals: int, company_cents: int, gci_cents: int}>, agents: list<array{name: string, deals: int, company_cents: int}>}
     */
    public function pending(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $buckets = [];
        foreach ([30, 60, 90] as $days) {
            $buckets[$days] = ['days' => $days, 'deals' => 0, 'company_cents' => 0, 'gci_cents' => 0];
        }
        $agents = [];
        foreach (Deal::query()->where('status', 'under_contract')->whereNotNull('calculation_id')->get() as $deal) {
            $closing = $deal->closing_date;
            $calc = Calculation::query()->find($deal->calculation_id);
            if ($closing === null || $calc === null || $closing->lt($today)) {
                continue;
            }
            $company = 0;
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                $company += (int) $row['company_cents'] + (int) ($row['post_cap_fee_cents'] ?? 0);
            }
            foreach ([30, 60, 90] as $days) {
                if ($closing->lte($today->addDays($days))) {
                    $buckets[$days]['deals']++;
                    $buckets[$days]['company_cents'] += $company;
                    $buckets[$days]['gci_cents'] += $deal->gci_cents;
                }
            }
            if ($closing->lte($today->addDays(90))) {
                $name = User::query()->find($deal->agent_user_id)->name ?? 'Agent';
                $agents[$name] ??= ['name' => $name, 'deals' => 0, 'company_cents' => 0];
                $agents[$name]['deals']++;
                $agents[$name]['company_cents'] += $company;
            }
        }
        usort($agents, fn ($a, $b) => $b['company_cents'] <=> $a['company_cents']);

        return ['buckets' => $buckets, 'agents' => $agents];
    }
}
