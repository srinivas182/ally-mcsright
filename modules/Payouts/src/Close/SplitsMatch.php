<?php

declare(strict_types=1);

namespace Modules\Payouts\Close;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Support\Connections;
use Modules\Ledger\Close\CloseCheck;
use Modules\Ledger\Close\Finding;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Payouts\Receipts;
use Modules\Transactions\Models\Deal;

/**
 * Each agent's commission booked this month matches what their plan works out for the deal now. A
 * difference means the calculation changed after the commission was booked (or the booking is wrong).
 */
final class SplitsMatch implements CloseCheck
{
    public function __construct(private readonly Receipts $receipts) {}

    public function key(): string
    {
        return 'splits_match';
    }

    public function label(): string
    {
        return 'Agent commission booked matches each agent\'s plan';
    }

    public function run(LedgerPeriod $period): array
    {
        $db = DB::connection(Connections::cell());
        $rows = $db->table('pay_recognitions')->join('ledger_journals', 'ledger_journals.id', '=', 'pay_recognitions.journal_id')
            ->where('pay_recognitions.tenant_id', $period->tenant_id)->where('ledger_journals.period_id', $period->id)
            ->get(['pay_recognitions.deal_id', 'pay_recognitions.journal_id', 'pay_recognitions.payables']);
        $out = [];
        foreach ($rows as $r) {
            $deal = Deal::query()->find((int) $r->deal_id);
            if ($deal === null) {
                continue;
            }
            $booked = $this->bookedPayables((int) $r->journal_id);
            $calc = $deal->calculation_id !== null ? Calculation::query()->find($deal->calculation_id) : null;
            $expected = $calc !== null ? array_filter($this->receipts->payablesFor($deal, $calc)[0], fn (int $c): bool => $c > 0) : null;
            $stored = array_filter(array_map('intval', (array) json_decode((string) $r->payables, true)), fn (int $c): bool => $c > 0);
            ksort($booked);
            ksort($stored);
            if ($booked !== $stored) {
                $out[] = Finding::stop("Commission for {$deal->street} was booked differently from its record",
                    'The amounts in the books do not match what was recorded at closing. Contact RightAlly support.', 'b'.$deal->ulid, '/deals/'.$deal->ulid);

                continue;
            }
            if ($expected === null) {
                continue;
            }
            ksort($expected);
            foreach (array_unique(array_merge(array_keys($booked), array_keys($expected))) as $userId) {
                $was = $booked[$userId] ?? 0;
                $now = $expected[$userId] ?? 0;
                if ($was !== $now) {
                    $name = User::query()->find($userId)->name ?? 'An agent';
                    $out[] = Finding::check("{$name}'s commission on {$deal->street} doesn't match their plan",
                        'Booked $'.number_format($was / 100, 2).'; the plan now works out $'.number_format($now / 100, 2).'. If the calculation was changed after closing, post an adjustment for the difference.',
                        'p'.$deal->ulid.'-'.$userId, '/deals/'.$deal->ulid);
                }
            }
        }

        return $out;
    }

    /** @return array<int, int> credits to each agent's commission payable in the journal */
    private function bookedPayables(int $journalId): array
    {
        $rows = DB::connection(Connections::cell())->table('ledger_lines')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.account_id')
            ->where('ledger_lines.journal_id', $journalId)->where('ledger_accounts.code', 'like', '2000-USER-%')
            ->get(['ledger_accounts.code', 'ledger_lines.credit_cents', 'ledger_lines.debit_cents']);
        $out = [];
        foreach ($rows as $l) {
            $userId = (int) substr((string) $l->code, strlen('2000-USER-'));
            $out[$userId] = ($out[$userId] ?? 0) + (int) $l->credit_cents - (int) $l->debit_cents;
        }

        return array_filter($out, fn (int $c): bool => $c > 0);
    }
}
