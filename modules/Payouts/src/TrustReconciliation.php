<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;

/**
 * Monthly three-way trust reconciliation (specs/transactions/01, addition 3). The adjusted bank balance
 * (statement balance + deposits in transit − outstanding withdrawals), the book balance (trust cash in
 * the ledger) and the clients' balance (every deal's escrow balance) must agree to the cent. Prepared by
 * one person and approved by another, then locked, with a report for the state.
 */
final class TrustReconciliation
{
    public const MATCH_DAYS = 5;

    public const STALE_DAYS = 10;

    public function __construct(private readonly TenantContext $tenants) {}

    /** Imports the trust bank statement (CSV from online banking) and matches it to the ledger. */
    public function import(string $month, string $csv, int $endingBalanceCents, User $by): string
    {
        $this->guard($by);
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => 'Choose the month.']);
        }
        $db = DB::connection(Connections::cell());
        if ($db->table('trust_statements')->where('month', $month)->where('status', 'approved')->exists()) {
            throw ValidationException::withMessages(['month' => 'This month is approved and locked.']);
        }
        $lines = $this->parse($csv);
        $ulid = (string) Str::ulid();
        $db->transaction(function () use ($db, $month, $csv, $endingBalanceCents, $by, $lines, $ulid): void {
            $db->table('trust_statements')->where('month', $month)->delete();
            $id = $db->table('trust_statements')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'month' => $month,
                'ending_balance_cents' => $endingBalanceCents, 'file_sha256' => hash('sha256', $csv), 'status' => 'draft', 'prepared_by' => $by->id,
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $l) {
                $db->table('trust_statement_lines')->insert(['tenant_id' => $this->tenants->id(), 'statement_id' => $id] + $l);
            }
        });
        $this->autoMatch($ulid);
        app(AuditLogger::class)->record('trust.statement.imported', metadata: ['month' => $month, 'lines' => count($lines), 'ending_cents' => $endingBalanceCents], severity: 'notice');

        return $ulid;
    }

    /** Matches statement lines to trust-cash ledger lines with the same amount within five days. */
    public function autoMatch(string $statementUlid): int
    {
        $db = DB::connection(Connections::cell());
        $statement = $db->table('trust_statements')->where('ulid', $statementUlid)->first();
        if ($statement === null) {
            return 0;
        }
        $ledgerLines = $this->unmatchedLedger(CarbonImmutable::parse($statement->month.'-01')->endOfMonth()->addDays(self::MATCH_DAYS));
        $matched = 0;
        foreach ($db->table('trust_statement_lines')->where('statement_id', $statement->id)->whereNotIn('id', $db->table('trust_matches')->select('statement_line_id'))->orderBy('posted_on')->get() as $line) {
            foreach ($ledgerLines as $i => $l) {
                $days = abs(CarbonImmutable::parse((string) $l['date'])->diffInDays(CarbonImmutable::parse((string) $line->posted_on)));
                if ($l['amount'] === (int) $line->amount_cents && $days <= self::MATCH_DAYS) {
                    $db->table('trust_matches')->insert(['tenant_id' => $this->tenants->id(), 'ledger_line_id' => $l['id'], 'statement_line_id' => $line->id, 'created_at' => now()]);
                    unset($ledgerLines[$i]);
                    $matched++;
                    break;
                }
            }
        }

        return $matched;
    }

    public function match(int $statementLineId, int $ledgerLineId, User $by): void
    {
        $this->guard($by);
        $db = DB::connection(Connections::cell());
        $line = $db->table('trust_statement_lines')->where('id', $statementLineId)->first();
        $ledger = collect($this->unmatchedLedger(CarbonImmutable::now()->addYear()))->firstWhere('id', $ledgerLineId);
        if ($line === null || $ledger === null || $ledger['amount'] !== (int) $line->amount_cents) {
            throw ValidationException::withMessages(['match' => 'Match a bank line to an unmatched trust entry of the same amount.']);
        }
        $db->table('trust_matches')->insert(['tenant_id' => $this->tenants->id(), 'ledger_line_id' => $ledgerLineId, 'statement_line_id' => $statementLineId, 'matched_by' => $by->id, 'created_at' => now()]);
    }

    /**
     * The three balances and everything that explains a difference.
     *
     * @return array<string, mixed>
     */
    public function summary(string $month): array
    {
        $db = DB::connection(Connections::cell());
        $statement = $db->table('trust_statements')->where('month', $month)->first();
        $end = CarbonImmutable::parse($month.'-01')->endOfMonth();
        $accounts = $this->accounts();
        $book = app(LedgerService::class)->balance($accounts->account('1010'), $end)->cents;

        $clients = [];
        $escrow = $accounts->account('2300');
        foreach ($db->table('ledger_accounts')->where('parent_id', $escrow->id)->where('subledger_type', 'deal')->get() as $acct) {
            // Money held for this deal (credit balance); negative means the deal is overdrawn.
            $balance = $this->creditBalance((int) $acct->id, $end);
            if ($balance === 0) {
                continue;
            }
            $deal = $db->table('tx_deals')->where('id', $acct->subledger_id)->first();
            $stale = $deal !== null && in_array($deal->status, ['closed', 'fell_through'], true)
                && CarbonImmutable::parse((string) ($deal->closed_on ?? $deal->updated_at))->addDays(self::STALE_DAYS)->lt($end);
            $clients[] = ['deal' => $deal !== null ? (string) $deal->street : 'Deal '.$acct->subledger_id, 'cents' => $balance, 'negative' => $balance < 0, 'stale' => $stale];
        }
        $clientTotal = array_sum(array_column($clients, 'cents'));

        $outstanding = $this->unmatchedLedger($end);
        $inTransit = array_sum(array_map(fn ($l) => max(0, $l['amount']), $outstanding));
        $withdrawals = array_sum(array_map(fn ($l) => min(0, $l['amount']), $outstanding));
        $unexplained = $statement === null ? [] : $db->table('trust_statement_lines')->where('statement_id', $statement->id)
            ->whereNotIn('id', $db->table('trust_matches')->select('statement_line_id'))->orderBy('posted_on')->get()
            ->map(fn ($l): array => ['id' => (int) $l->id, 'date' => (string) $l->posted_on, 'description' => (string) $l->description, 'cents' => (int) $l->amount_cents])->values()->all();
        $bank = $statement !== null ? (int) $statement->ending_balance_cents : null;
        $adjusted = $bank !== null ? $bank + $inTransit + $withdrawals : null;

        return [
            'month' => $month, 'status' => $statement->status ?? 'missing', 'statement' => $statement?->ulid,
            'bank_cents' => $bank, 'adjusted_bank_cents' => $adjusted, 'book_cents' => $book, 'clients_cents' => $clientTotal,
            'balanced' => $adjusted !== null && $adjusted === $book && $book === $clientTotal,
            'outstanding' => $outstanding, 'unexplained' => $unexplained, 'clients' => $clients,
            'negative_clients' => count(array_filter($clients, fn ($c) => $c['negative'])),
            'prepared_by' => $statement !== null ? User::query()->find((int) $statement->prepared_by)?->name : null,
            'approved_by' => $statement?->approved_by !== null ? User::query()->find((int) $statement->approved_by)?->name : null,
            'approved_at' => $statement->approved_at ?? null,
        ];
    }

    public function approve(string $month, User $by): void
    {
        $this->guard($by);
        $s = $this->summary($month);
        $statement = DB::connection(Connections::cell())->table('trust_statements')->where('month', $month)->where('status', 'draft')->first();
        if ($statement === null) {
            throw ValidationException::withMessages(['approve' => 'Import this month\'s trust bank statement first.']);
        }
        if ((int) $statement->prepared_by === $by->id) {
            throw ValidationException::withMessages(['approve' => 'A different person must approve the reconciliation they did not prepare.']);
        }
        if (! $s['balanced'] || $s['negative_clients'] > 0) {
            throw ValidationException::withMessages(['approve' => $s['negative_clients'] > 0 ? 'A deal has a negative trust balance; resolve it before approving.' : 'The three balances do not agree yet.']);
        }
        DB::connection(Connections::cell())->table('trust_statements')->where('id', $statement->id)->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(),
            'snapshot' => json_encode(['bank' => $s['bank_cents'], 'adjusted_bank' => $s['adjusted_bank_cents'], 'book' => $s['book_cents'], 'clients' => $s['clients_cents']]), 'updated_at' => now()]);
        app(AuditLogger::class)->record('trust.reconciliation.approved', metadata: ['month' => $month, 'book_cents' => $s['book_cents']], severity: 'warning');
    }

    /** On the 10th: owners are told if last month's trust account is not reconciled. */
    public function overdue(CarbonImmutable $today): bool
    {
        $month = $today->subMonthNoOverflow()->format('Y-m');
        $hasTrust = DB::connection(Connections::cell())->table('tx_deposits')->where('held_by', 'trust')->exists();

        return $hasTrust && ! DB::connection(Connections::cell())->table('trust_statements')->where('month', $month)->where('status', 'approved')->exists();
    }

    /**
     * Trust-cash ledger lines up to a date that no bank line has matched yet (signed: + in, − out).
     *
     * @return array<int, array{id: int, date: string, description: string, amount: int}>
     */
    public function unmatchedLedger(CarbonImmutable $upTo): array
    {
        $db = DB::connection(Connections::cell());
        $trust = $this->accounts()->account('1010');

        return $db->table('ledger_lines')->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_lines.journal_id')
            ->where('ledger_lines.account_id', $trust->id)->where('ledger_journals.effective_date', '<=', $upTo->toDateString())
            ->whereNotIn('ledger_lines.id', $db->table('trust_matches')->select('ledger_line_id'))->orderBy('ledger_journals.effective_date')
            ->get(['ledger_lines.id', 'ledger_journals.effective_date', 'ledger_journals.description', 'ledger_lines.debit_cents', 'ledger_lines.credit_cents'])
            ->map(fn ($l): array => ['id' => (int) $l->id, 'date' => (string) $l->effective_date, 'description' => (string) $l->description,
                'amount' => (int) $l->debit_cents - (int) $l->credit_cents])->values()->all();
    }

    /**
     * @return list<array{posted_on: string, description: string, amount_cents: int}>
     */
    private function parse(string $csv): array
    {
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\n/', trim($csv)) ?: []), fn ($r) => count($r) > 1));
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has no transactions.']);
        }
        $head = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0]);
        $col = fn (array $names): ?int => ($i = collect($head)->search(fn ($h) => in_array($h, $names, true))) === false ? null : (int) $i;
        $date = $col(['date', 'posted', 'posting date', 'transaction date']);
        $desc = $col(['description', 'memo', 'details', 'payee']);
        $amount = $col(['amount']);
        $debit = $col(['debit', 'withdrawal', 'withdrawals']);
        $credit = $col(['credit', 'deposit', 'deposits']);
        if ($date === null || ($amount === null && ($debit === null || $credit === null))) {
            throw ValidationException::withMessages(['file' => 'The file needs a Date column and an Amount column (or Debit and Credit columns).']);
        }
        $money = fn (?string $v): int => $v === null || trim($v) === '' ? 0 : (int) round((float) str_replace([',', '$', ' '], '', $v) * 100);
        $out = [];
        foreach (array_slice($rows, 1) as $r) {
            $cents = $amount !== null ? $money($r[$amount] ?? null) : $money($r[$credit] ?? null) - abs($money($r[$debit] ?? null));
            $out[] = ['posted_on' => CarbonImmutable::parse((string) $r[$date])->toDateString(), 'description' => mb_substr(trim((string) ($r[$desc ?? 0] ?? '')), 0, 200), 'amount_cents' => $cents];
        }

        return $out;
    }

    private function creditBalance(int $accountId, CarbonImmutable $asOf): int
    {
        $row = DB::connection(Connections::cell())->table('ledger_lines')->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_lines.journal_id')
            ->where('ledger_lines.account_id', $accountId)->where('ledger_journals.effective_date', '<=', $asOf->toDateString())
            ->selectRaw('coalesce(sum(credit_cents), 0) - coalesce(sum(debit_cents), 0) as bal')->value('bal');

        return (int) $row;
    }

    private function accounts(): ChartOfAccounts
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();

        return $accounts;
    }

    private function guard(User $by): void
    {
        if (! ($by->isKind(UserKind::Owner) || $by->can('transactions.trust.manage'))) {
            throw ValidationException::withMessages(['trust' => 'Trust reconciliation needs the "Record earnest money" permission.']);
        }
    }
}
