<?php

declare(strict_types=1);

namespace Modules\Ledger\Accounting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Money\Money;

/**
 * The operating bank account, month by month (AC1, specs/finance/20). Import the statement your bank lets you
 * download (CSV, or OFX/QFX "Quicken" files); each line is matched to the books (same amount, within 5 days).
 * What the bank shows but the books don't (bank fees, a card charge) is added to the books from here in one click with
 * a category. The month balances when the bank's ending balance, plus deposits not yet on the statement, minus
 * payments not yet cleared, equals the books' operating cash. A second person approves it, and it is then locked.
 */
final class BankReconciliation
{
    public const MATCH_DAYS = 5;

    public function __construct(private readonly TenantContext $tenants, private readonly ChartOfAccounts $chart, private readonly LedgerService $ledger) {}

    public function import(string $month, string $file, int $endingBalanceCents, User $by): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => 'Choose the month.']);
        }
        $db = DB::connection(Connections::cell());
        if ($db->table('acct_bank_statements')->where('tenant_id', $this->tenants->id())->where('month', $month)->where('status', 'approved')->exists()) {
            throw ValidationException::withMessages(['month' => 'This month is approved and locked.']);
        }
        $lines = str_contains(strtoupper(substr($file, 0, 4000)), '<STMTTRN>') ? self::parseOfx($file) : self::parseCsv($file);
        $ulid = (string) Str::ulid();
        $db->transaction(function () use ($db, $month, $file, $endingBalanceCents, $by, $lines, $ulid): void {
            $old = $db->table('acct_bank_statements')->where('tenant_id', $this->tenants->id())->where('month', $month)->value('id');
            if ($old !== null) {
                $db->table('acct_bank_lines')->where('statement_id', $old)->delete();
                $db->table('acct_bank_statements')->where('id', $old)->delete();
            }
            $id = $db->table('acct_bank_statements')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'month' => $month, 'ending_balance_cents' => $endingBalanceCents,
                'file_sha256' => hash('sha256', $file), 'status' => 'draft', 'prepared_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $l) {
                $db->table('acct_bank_lines')->insert($l + ['tenant_id' => $this->tenants->id(), 'statement_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->autoMatch($ulid);
        app(AuditLogger::class)->record('finance.bank_statement_imported', metadata: ['month' => $month, 'lines' => count($lines)], severity: 'notice');

        return $ulid;
    }

    public function autoMatch(string $statementUlid): int
    {
        $db = DB::connection(Connections::cell());
        $statement = $db->table('acct_bank_statements')->where('tenant_id', $this->tenants->id())->where('ulid', $statementUlid)->first() ?? abort(404);
        $books = $this->unmatchedBooks(CarbonImmutable::parse($statement->month.'-01')->endOfMonth()->addDays(self::MATCH_DAYS));
        $matched = 0;
        foreach ($db->table('acct_bank_lines')->where('statement_id', $statement->id)->whereNull('ledger_line_id')->orderBy('posted_on')->get() as $line) {
            foreach ($books as $i => $b) {
                if ($b['amount_cents'] === (int) $line->amount_cents && abs(CarbonImmutable::parse($b['date'])->diffInDays(CarbonImmutable::parse((string) $line->posted_on))) <= self::MATCH_DAYS) {
                    $db->table('acct_bank_lines')->where('id', $line->id)->update(['ledger_line_id' => $b['id'], 'updated_at' => now()]);
                    unset($books[$i]);
                    $matched++;
                    break;
                }
            }
        }

        return $matched;
    }

    /** A bank line that isn't in the books yet (a fee, a card charge, interest): added with a category, and matched. */
    public function addToBooks(int $lineId, string $accountCode, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $line = $db->table('acct_bank_lines')->where('tenant_id', $this->tenants->id())->where('id', $lineId)->whereNull('ledger_line_id')->first()
            ?? throw ValidationException::withMessages(['line' => 'That line is already in the books.']);
        $this->guardOpen((int) $line->statement_id);
        $in = (int) $line->amount_cents > 0;
        $account = LedgerAccount::query()->where('code', $accountCode)->where('status', 'active')->whereIn('type', $in ? [AccountType::Revenue, AccountType::Liability, AccountType::Equity] : [AccountType::Expense, AccountType::Liability, AccountType::Equity])->first()
            ?? throw ValidationException::withMessages(['account_code' => $in ? 'Choose what the money in was.' : 'Choose what the payment was for.']);
        $cash = $this->chart->account('1000');
        $amount = Money::of(abs((int) $line->amount_cents));
        $draft = JournalDraft::make('bank_entry', 'bank-line:'.$line->id)->effectiveOn(CarbonImmutable::parse((string) $line->posted_on))->source('bank_line', (string) $line->id)->by($by->id)
            ->describe('From the bank statement: '.$line->description);
        $journal = $this->ledger->post($in ? $draft->debit($cash, $amount)->credit($account, $amount) : $draft->debit($account, $amount)->credit($cash, $amount));
        $cashLine = $db->table('ledger_lines')->where('journal_id', $journal->id)->where('account_id', $cash->id)->value('id');
        $db->table('acct_bank_lines')->where('id', $line->id)->update(['ledger_line_id' => $cashLine, 'journal_id' => $journal->id, 'updated_at' => now()]);
    }

    public function match(int $lineId, int $ledgerLineId, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $line = $db->table('acct_bank_lines')->where('tenant_id', $this->tenants->id())->where('id', $lineId)->whereNull('ledger_line_id')->first();
        $book = collect($this->unmatchedBooks(CarbonImmutable::now()->addYear()))->firstWhere('id', $ledgerLineId);
        if ($line === null || $book === null || $book['amount_cents'] !== (int) $line->amount_cents) {
            throw ValidationException::withMessages(['match' => 'Match a bank line to an entry in the books of the same amount.']);
        }
        $this->guardOpen((int) $line->statement_id);
        $db->table('acct_bank_lines')->where('id', $line->id)->update(['ledger_line_id' => $ledgerLineId, 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.bank_line_matched', metadata: ['line' => $lineId, 'ledger_line' => $ledgerLineId, 'by' => $by->id]);
    }

    /**
     * @return array{month: string, statement: array<string, mixed>|null, bank_cents: int|null, book_cents: int, in_transit_cents: int, outstanding_cents: int, adjusted_cents: int|null, difference_cents: int|null, unmatched_bank: list<array<string, mixed>>, unmatched_books: list<array{id: int, date: string, description: string, amount_cents: int}>, matched: int, can_approve: bool}
     */
    public function summary(string $month, ?User $viewer = null): array
    {
        $db = DB::connection(Connections::cell());
        $end = CarbonImmutable::parse($month.'-01')->endOfMonth();
        $st = $db->table('acct_bank_statements')->where('tenant_id', $this->tenants->id())->where('month', $month)->first();
        $book = $this->ledger->balance($this->chart->account('1000'), $end)->cents;
        $books = array_values(array_filter($this->unmatchedBooks($end), fn (array $b): bool => $b['date'] <= $end->toDateString()));
        $inTransit = array_sum(array_map(fn (array $b): int => max(0, $b['amount_cents']), $books));
        $outstanding = array_sum(array_map(fn (array $b): int => min(0, $b['amount_cents']), $books));
        $lines = $st !== null ? $db->table('acct_bank_lines')->where('statement_id', $st->id)->orderBy('posted_on')->get() : collect();
        $unmatchedBank = array_values($lines->whereNull('ledger_line_id')->map(fn ($l): array => ['id' => (int) $l->id, 'date' => (string) $l->posted_on, 'description' => (string) $l->description,
            'amount_cents' => (int) $l->amount_cents])->all());
        $adjusted = $st !== null ? (int) $st->ending_balance_cents + $inTransit + $outstanding : null;
        $difference = $adjusted !== null ? $adjusted - $book : null;

        return ['month' => $month, 'statement' => $st !== null ? ['ulid' => (string) $st->ulid, 'status' => (string) $st->status, 'prepared_by' => (int) $st->prepared_by,
            'approved_at' => $st->approved_at !== null ? (string) $st->approved_at : null] : null,
            'bank_cents' => $st !== null ? (int) $st->ending_balance_cents : null, 'book_cents' => $book, 'in_transit_cents' => $inTransit, 'outstanding_cents' => $outstanding,
            'adjusted_cents' => $adjusted, 'difference_cents' => $difference, 'unmatched_bank' => $unmatchedBank, 'unmatched_books' => $books,
            'matched' => $lines->whereNotNull('ledger_line_id')->count(),
            'can_approve' => $st !== null && $st->status === 'draft' && $difference === 0 && $unmatchedBank === [] && $viewer !== null && $viewer->id !== (int) $st->prepared_by];
    }

    public function approve(string $month, User $by): void
    {
        $s = $this->summary($month, $by);
        if ($s['statement'] === null || $s['statement']['status'] !== 'draft') {
            throw ValidationException::withMessages(['month' => 'Import this month\'s statement first.']);
        }
        if ($s['statement']['prepared_by'] === $by->id) {
            throw ValidationException::withMessages(['month' => 'Someone other than the person who prepared it must approve it.']);
        }
        if ($s['difference_cents'] !== 0 || $s['unmatched_bank'] !== []) {
            throw ValidationException::withMessages(['month' => 'It doesn\'t balance yet: match or add every bank line first.']);
        }
        DB::connection(Connections::cell())->table('acct_bank_statements')->where('tenant_id', $this->tenants->id())->where('month', $month)
            ->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.bank_reconciliation_approved', metadata: ['month' => $month], severity: 'notice');
    }

    /**
     * Operating cash entries in the books not yet matched to a bank line, up to a date (money in positive).
     *
     * @return list<array{id: int, date: string, description: string, amount_cents: int}>
     */
    public function unmatchedBooks(CarbonImmutable $upTo): array
    {
        $db = DB::connection(Connections::cell());
        $cash = $this->chart->account('1000');

        return array_values($db->table('ledger_lines')->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_lines.journal_id')
            ->where('ledger_lines.account_id', $cash->id)->where('ledger_journals.effective_date', '<=', $upTo->toDateString())
            ->whereNotIn('ledger_lines.id', $db->table('acct_bank_lines')->where('tenant_id', $this->tenants->id())->whereNotNull('ledger_line_id')->select('ledger_line_id'))
            ->orderBy('ledger_journals.effective_date')->limit(1000)
            ->get(['ledger_lines.id', 'ledger_journals.effective_date', 'ledger_journals.description', 'ledger_lines.debit_cents', 'ledger_lines.credit_cents'])
            ->map(fn ($r): array => ['id' => (int) $r->id, 'date' => substr((string) $r->effective_date, 0, 10), 'description' => (string) ($r->description ?? ''),
                'amount_cents' => (int) $r->debit_cents - (int) $r->credit_cents])->all());
    }

    private function guardOpen(int $statementId): void
    {
        if (DB::connection(Connections::cell())->table('acct_bank_statements')->where('id', $statementId)->value('status') === 'approved') {
            throw ValidationException::withMessages(['month' => 'This month is approved and locked.']);
        }
    }

    /**
     * @return list<array{posted_on: string, description: string, amount_cents: int, fitid: string|null}>
     */
    public static function parseCsv(string $csv): array
    {
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\n/', trim($csv)) ?: []), fn ($r) => count($r) > 1));
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has no transactions.']);
        }
        $head = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0]);
        $col = fn (array $names): ?int => ($i = collect($head)->search(fn ($h) => in_array($h, $names, true))) === false ? null : (int) $i;
        $date = $col(['date', 'posted', 'posting date', 'transaction date', 'post date']);
        $desc = $col(['description', 'memo', 'details', 'payee', 'name']);
        $amount = $col(['amount']);
        $debit = $col(['debit', 'withdrawal', 'withdrawals']);
        $credit = $col(['credit', 'deposit', 'deposits']);
        if ($date === null || ($amount === null && ($debit === null || $credit === null))) {
            throw ValidationException::withMessages(['file' => 'The file needs a Date column and an Amount column (or Debit and Credit columns).']);
        }
        $money = fn (?string $v): int => $v === null || trim($v) === '' ? 0 : (int) round((float) str_replace([',', '$', ' '], '', $v) * 100);
        $out = [];
        foreach (array_slice($rows, 1) as $r) {
            try {
                $on = CarbonImmutable::parse((string) ($r[$date] ?? ''))->toDateString();
            } catch (\Throwable) {
                continue;
            }
            $cents = $amount !== null ? $money($r[$amount] ?? null) : $money($r[$credit] ?? null) - abs($money($r[$debit] ?? null));
            if ($cents !== 0) {
                $out[] = ['posted_on' => $on, 'description' => mb_substr(trim((string) ($r[$desc ?? 0] ?? '')), 0, 300), 'amount_cents' => $cents, 'fitid' => null];
            }
        }

        return $out;
    }

    /**
     * OFX / QFX (the "Quicken" or "Money" download most US banks offer).
     *
     * @return list<array{posted_on: string, description: string, amount_cents: int, fitid: string|null}>
     */
    public static function parseOfx(string $ofx): array
    {
        $out = [];
        preg_match_all('/<STMTTRN>(.*?)(<\/STMTTRN>|(?=<STMTTRN>)|<\/BANKTRANLIST>)/is', $ofx, $blocks);
        foreach ($blocks[1] as $b) {
            $tag = fn (string $t): ?string => preg_match('/<'.$t.'>([^<\r\n]*)/i', $b, $m) === 1 ? trim($m[1]) : null;
            $posted = $tag('DTPOSTED');
            $amount = $tag('TRNAMT');
            if ($posted === null || $amount === null || preg_match('/^(\d{4})(\d{2})(\d{2})/', $posted, $d) !== 1) {
                continue;
            }
            $out[] = ['posted_on' => "{$d[1]}-{$d[2]}-{$d[3]}", 'description' => mb_substr(trim(($tag('NAME') ?? '').' '.($tag('MEMO') ?? '')), 0, 300) ?: 'Bank transaction',
                'amount_cents' => (int) round((float) $amount * 100), 'fitid' => $tag('FITID')];
        }
        if ($out === []) {
            throw ValidationException::withMessages(['file' => 'No transactions found in the OFX/QFX file.']);
        }

        return $out;
    }
}
