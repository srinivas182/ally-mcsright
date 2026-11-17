<?php

declare(strict_types=1);

namespace Modules\Ledger\Close\Checks;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Ledger\Close\CloseCheck;
use Modules\Ledger\Close\Finding;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerBalance;
use Modules\Ledger\Models\LedgerPeriod;

/** Every entry in the month balances, and every account's running balance equals its entries. */
final class BooksBalance implements CloseCheck
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function key(): string
    {
        return 'books_balance';
    }

    public function label(): string
    {
        return 'Every entry balances and every account total adds up';
    }

    public function run(LedgerPeriod $period): array
    {
        $out = [];
        $unbalanced = DB::connection(Connections::cell())->table('ledger_lines')->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_lines.journal_id')
            ->where('ledger_journals.tenant_id', $period->tenant_id)->where('ledger_journals.period_id', $period->id)
            ->groupBy('ledger_journals.id', 'ledger_journals.ulid')->havingRaw('sum(ledger_lines.debit_cents) <> sum(ledger_lines.credit_cents)')
            ->limit(20)->pluck('ledger_journals.ulid');
        foreach ($unbalanced as $ulid) {
            $out[] = Finding::stop('An entry does not balance', 'Its debits and credits are not equal. Contact RightAlly support; this should never happen.', 'j'.$ulid, '/finance/journals/'.$ulid);
        }
        foreach (LedgerAccount::query()->get() as $account) {
            $stored = (int) (LedgerBalance::query()->whereKey($account->id)->value('net_debit_cents') ?? 0);
            if ($stored !== $this->ledger->netDebitFromLines($account)) {
                $out[] = Finding::stop("Account {$account->code} total is out of step", "The running total for {$account->name} does not equal its entries. Contact RightAlly support; this should never happen.", 'a'.$account->code);
            }
        }

        return $out;
    }
}
