<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Support\Connections;
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

    /**
     * Week by week for the next 13 weeks (LM1): what is expected to close each week, and the company dollar it brings,
     * plus the deals behind each figure so the broker can see which ones carry the month. A deal whose key dates are
     * overdue is marked at risk.
     *
     * @return list<array{week_of: string, deals: list<array{ulid: string, street: string, agent: string, closing_date: string, company_cents: int, gci_cents: int, at_risk: bool}>, company_cents: int, gci_cents: int}>
     */
    public function weekly(?CarbonImmutable $today = null, int $weeks = 13): array
    {
        $today ??= CarbonImmutable::today();
        $start = $today->startOfWeek();
        $out = [];
        for ($w = 0; $w < $weeks; $w++) {
            $out[$w] = ['week_of' => $start->addWeeks($w)->toDateString(), 'deals' => [], 'company_cents' => 0, 'gci_cents' => 0];
        }
        $deals = Deal::query()->where('status', 'under_contract')->whereNotNull('closing_date')->whereBetween('closing_date', [$today->toDateString(), $start->addWeeks($weeks)->subDay()->toDateString()])
            ->orderBy('closing_date')->get();
        $names = User::query()->whereIn('id', $deals->pluck('agent_user_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $overdue = DB::connection(Connections::cell())->table('tx_dates')->whereIn('deal_id', $deals->pluck('id')->all() ?: [0])->whereNull('done_at')
            ->where('due_on', '<', $today->toDateString())->pluck('deal_id')->unique()->flip();
        foreach ($deals as $deal) {
            $closing = $deal->closing_date;
            if ($closing === null) {
                continue;
            }
            $w = intdiv((int) $start->diffInDays($closing), 7);
            if (! isset($out[$w])) {
                continue;
            }
            $company = self::companyCents($deal);
            $out[$w]['deals'][] = ['ulid' => $deal->ulid, 'street' => $deal->street, 'agent' => (string) ($names->get($deal->agent_user_id)->name ?? 'Agent'),
                'closing_date' => $closing->toDateString(), 'company_cents' => $company, 'gci_cents' => $deal->gci_cents, 'at_risk' => $overdue->has($deal->id)];
            $out[$w]['company_cents'] += $company;
            $out[$w]['gci_cents'] += $deal->gci_cents;
        }

        return array_values($out);
    }

    private static function companyCents(Deal $deal): int
    {
        $calc = $deal->calculation_id !== null ? Calculation::query()->find($deal->calculation_id) : null;
        $company = 0;
        foreach ((array) ($calc->result['agents'] ?? []) as $row) {
            $company += (int) ($row['company_cents'] ?? 0) + (int) ($row['post_cap_fee_cents'] ?? 0);
        }

        return $company;
    }
}
