<?php

declare(strict_types=1);

namespace Modules\Transactions\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Compensation\Models\Calculation;
use Modules\Transactions\Models\Deal;

/**
 * Franchise and royalty report (LM1, specs/transactions/30): for deals closed in a period, every fee the commission
 * plan says is payable to the franchisor (royalty, franchise fee, marketing fund), deal by deal and in total, ready
 * to send with the monthly royalty payment. Older calculations without a payee fall back to fee codes containing
 * "royalty" or "franchise".
 */
final class Royalties
{
    /**
     * @return array{from: string, to: string, rows: list<array{closed_on: string, street: string, agent: string, gci_cents: int, fee: string, code: string, cents: int}>, by_fee: list<array{fee: string, code: string, deals: int, cents: int}>, total_cents: int, gci_cents: int, deals: int}
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $deals = Deal::query()->where('status', 'closed')->whereBetween('closed_on', [$from->toDateString(), $to->toDateString()])->whereNotNull('calculation_id')
            ->orderBy('closed_on')->orderBy('id')->get();
        $calcs = Calculation::query()->whereIn('id', $deals->pluck('calculation_id')->all() ?: [0])->get()->keyBy('id');
        $names = User::query()->whereIn('id', $deals->pluck('agent_user_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $rows = [];
        $byFee = [];
        $gci = 0;
        $dealIds = [];
        foreach ($deals as $deal) {
            $calc = $calcs->get($deal->calculation_id);
            $result = $calc !== null ? (array) $calc->result : [];
            foreach ((array) ($result['agents'] ?? []) as $agent) {
                foreach ((array) (is_array($agent) ? ($agent['fees'] ?? []) : []) as $fee) {
                    if (! is_array($fee) || ! self::franchisor($fee)) {
                        continue;
                    }
                    $code = (string) ($fee['code'] ?? '');
                    $name = (string) ($fee['name'] ?? ucfirst(str_replace('_', ' ', $code)));
                    $cents = (int) ($fee['cents'] ?? 0);
                    $rows[] = ['closed_on' => (string) $deal->closed_on?->toDateString(), 'street' => $deal->street, 'agent' => (string) ($names->get($deal->agent_user_id)->name ?? 'Agent'),
                        'gci_cents' => $deal->gci_cents, 'fee' => $name, 'code' => $code, 'cents' => $cents];
                    $byFee[$code] ??= ['fee' => $name, 'code' => $code, 'deals' => [], 'cents' => 0];
                    $byFee[$code]['deals'][$deal->id] = true;
                    $byFee[$code]['cents'] += $cents;
                    if (! isset($dealIds[$deal->id])) {
                        $dealIds[$deal->id] = true;
                        $gci += $deal->gci_cents;
                    }
                }
            }
        }
        $fees = array_values(array_map(fn (array $f): array => ['fee' => $f['fee'], 'code' => $f['code'], 'deals' => count($f['deals']), 'cents' => $f['cents']], $byFee));

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows, 'by_fee' => $fees, 'total_cents' => array_sum(array_column($rows, 'cents')),
            'gci_cents' => $gci, 'deals' => count($dealIds)];
    }

    /**
     * @param  array<mixed>  $fee
     */
    private static function franchisor(array $fee): bool
    {
        if (isset($fee['payable_to'])) {
            return $fee['payable_to'] === 'franchisor';
        }

        return preg_match('/royalt|franchis/i', (string) ($fee['code'] ?? '')) === 1;
    }
}
