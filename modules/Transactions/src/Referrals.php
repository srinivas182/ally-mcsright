<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;

/**
 * Referrals in and out (specs/transactions/01, addition 8): outgoing referral fees feed the commission
 * calculation as the outside referral; fees paid to other brokerages are reported for 1099-NEC.
 */
final class Referrals
{
    public function add(Deal $deal, string $direction, string $brokerage, string $agentName, ?string $agentEmail, string $percent, ?int $agreementDocumentId): string
    {
        if (! in_array($direction, ['in', 'out'], true) || trim($brokerage) === '' || trim($agentName) === ''
            || ! is_numeric($percent) || ! BigDecimal::of($percent)->isPositive() || BigDecimal::of($percent)->isGreaterThan(100)) {
            throw ValidationException::withMessages(['referral' => 'Give the direction, the other brokerage and agent, and a percentage of the commission.']);
        }
        if ($direction === 'out' && $deal->status !== 'pre_contract') {
            throw ValidationException::withMessages(['referral' => 'Add outgoing referrals before the deal goes under contract, so the commission includes them.']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('tx_referrals')->insert(['ulid' => $ulid, 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id,
            'direction' => $direction, 'brokerage' => trim($brokerage), 'agent_name' => trim($agentName), 'agent_email' => $agentEmail, 'percent' => $percent,
            'agreement_document_id' => $agreementDocumentId, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.referral.added', $deal, metadata: ['direction' => $direction, 'brokerage' => $brokerage, 'percent' => $percent], label: $deal->street);

        return $ulid;
    }

    /** Total outgoing referral percentage for the commission calculation, or null. */
    public function outPercent(Deal $deal): ?string
    {
        $total = DB::connection(Connections::cell())->table('tx_referrals')->where('deal_id', $deal->id)->where('direction', 'out')->pluck('percent')
            ->reduce(fn (BigDecimal $sum, $p): BigDecimal => $sum->plus((string) $p), BigDecimal::zero());

        return $total->isPositive() ? (string) $total : null;
    }

    /**
     * Fees paid to other brokerages on deals closed in a year (for 1099-NEC).
     *
     * @return list<array{brokerage: string, agent: string, deals: int, paid_cents: int, w9: bool}>
     */
    public function paidInYear(int $year): array
    {
        $rows = DB::connection(Connections::cell())->table('tx_referrals')->join('tx_deals', 'tx_deals.id', '=', 'tx_referrals.deal_id')
            ->where('tx_referrals.direction', 'out')->where('tx_deals.status', 'closed')->whereYear('tx_deals.closed_on', $year)
            ->get(['tx_referrals.brokerage', 'tx_referrals.agent_name', 'tx_referrals.percent', 'tx_referrals.w9_received', 'tx_deals.gci_cents']);
        $out = [];
        foreach ($rows as $r) {
            $key = $r->brokerage.'|'.$r->agent_name;
            $paid = BigDecimal::of((int) $r->gci_cents)->multipliedBy((string) $r->percent)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
            $out[$key] ??= ['brokerage' => (string) $r->brokerage, 'agent' => (string) $r->agent_name, 'deals' => 0, 'paid_cents' => 0, 'w9' => true];
            $out[$key]['deals']++;
            $out[$key]['paid_cents'] += $paid;
            $out[$key]['w9'] = $out[$key]['w9'] && (bool) $r->w9_received;
        }

        return array_values($out);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forDeal(Deal $deal): array
    {
        return DB::connection(Connections::cell())->table('tx_referrals')->where('deal_id', $deal->id)->orderBy('id')->get()
            ->map(fn ($r): array => ['ulid' => $r->ulid, 'direction' => $r->direction, 'brokerage' => $r->brokerage, 'agent' => $r->agent_name,
                'percent' => (string) $r->percent, 'w9' => (bool) $r->w9_received])->values()->all();
    }
}
