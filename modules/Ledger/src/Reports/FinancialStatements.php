<?php

declare(strict_types=1);

namespace Modules\Ledger\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\Models\LedgerAccount;

/**
 * Profit and loss, and the balance sheet (AC2, specs/finance/21), straight from the ledger: every posted journal,
 * sub-accounts (one per agent, per deal) rolled up into their parent so the report reads like a brokerage's books,
 * with the same period last year beside it. The balance sheet always balances: this year's profit so far is shown
 * as "Profit this year" under equity until the year is closed.
 */
final class FinancialStatements
{
    public function __construct(private readonly ChartOfAccounts $chart, private readonly TenantContext $tenants) {}

    /**
     * @return array{from: string, to: string, compare_from: string, compare_to: string, revenue: list<array{code: string, name: string, cents: int, prior_cents: int}>, expenses: list<array{code: string, name: string, cents: int, prior_cents: int}>, revenue_cents: int, expense_cents: int, net_cents: int, prior_revenue_cents: int, prior_expense_cents: int, prior_net_cents: int}
     */
    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->chart->seed();
        $priorFrom = $from->subYear();
        $priorTo = $to->subYear();
        $now = $this->movement($from, $to);
        $prior = $this->movement($priorFrom, $priorTo);
        $rows = ['revenue' => [], 'expenses' => []];
        foreach ($this->parents([AccountType::Revenue, AccountType::Expense]) as $a) {
            $sign = $a->type === AccountType::Revenue ? -1 : 1; // revenue is a credit balance
            $cents = $sign * ($now[$a->id] ?? 0);
            $priorCents = $sign * ($prior[$a->id] ?? 0);
            if ($cents !== 0 || $priorCents !== 0) {
                $rows[$a->type === AccountType::Revenue ? 'revenue' : 'expenses'][] = ['code' => $a->code, 'name' => $a->name, 'cents' => $cents, 'prior_cents' => $priorCents];
            }
        }
        $rev = array_sum(array_column($rows['revenue'], 'cents'));
        $exp = array_sum(array_column($rows['expenses'], 'cents'));
        $pRev = array_sum(array_column($rows['revenue'], 'prior_cents'));
        $pExp = array_sum(array_column($rows['expenses'], 'prior_cents'));

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'compare_from' => $priorFrom->toDateString(), 'compare_to' => $priorTo->toDateString(),
            'revenue' => $rows['revenue'], 'expenses' => $rows['expenses'], 'revenue_cents' => $rev, 'expense_cents' => $exp, 'net_cents' => $rev - $exp,
            'prior_revenue_cents' => $pRev, 'prior_expense_cents' => $pExp, 'prior_net_cents' => $pRev - $pExp];
    }

    /**
     * @return array{as_of: string, assets: list<array{code: string, name: string, cents: int}>, liabilities: list<array{code: string, name: string, cents: int}>, equity: list<array{code: string, name: string, cents: int}>, assets_cents: int, liabilities_cents: int, equity_cents: int, balanced: bool}
     */
    public function balanceSheet(CarbonImmutable $asOf): array
    {
        $this->chart->seed();
        $all = $this->movement(null, $asOf);
        $out = ['assets' => [], 'liabilities' => [], 'equity' => []];
        foreach ($this->parents([AccountType::Asset, AccountType::Liability, AccountType::Equity]) as $a) {
            $cents = ($a->type === AccountType::Asset ? 1 : -1) * ($all[$a->id] ?? 0);
            if ($cents !== 0) {
                $out[match ($a->type) {
                    AccountType::Asset => 'assets', AccountType::Liability => 'liabilities', default => 'equity'
                }][] = ['code' => $a->code, 'name' => $a->name, 'cents' => $cents];
            }
        }
        // Profit not yet closed into equity: revenue minus expenses, all time to the date.
        $profit = 0;
        foreach ($this->parents([AccountType::Revenue, AccountType::Expense]) as $a) {
            $profit += -1 * ($all[$a->id] ?? 0);
        }
        if ($profit !== 0) {
            $out['equity'][] = ['code' => '', 'name' => 'Profit not yet closed into equity', 'cents' => $profit];
        }
        $assets = array_sum(array_column($out['assets'], 'cents'));
        $liabilities = array_sum(array_column($out['liabilities'], 'cents'));
        $equity = array_sum(array_column($out['equity'], 'cents'));

        return ['as_of' => $asOf->toDateString()] + $out + ['assets_cents' => $assets, 'liabilities_cents' => $liabilities, 'equity_cents' => $equity,
            'balanced' => $assets === $liabilities + $equity];
    }

    /**
     * Net debit (debit − credit) per top-level account between two dates, sub-accounts rolled up.
     *
     * @return array<int, int>
     */
    private function movement(?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::connection(Connections::cell())->table('ledger_lines')->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_lines.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.account_id')
            ->where('ledger_lines.tenant_id', $this->tenants->id())->where('ledger_journals.effective_date', '<=', $to->toDateString())
            ->when($from !== null, fn ($q) => $q->where('ledger_journals.effective_date', '>=', $from?->toDateString()))
            ->groupByRaw('coalesce(ledger_accounts.parent_id, ledger_accounts.id)')
            ->selectRaw('coalesce(ledger_accounts.parent_id, ledger_accounts.id) as top, sum(ledger_lines.debit_cents) - sum(ledger_lines.credit_cents) as net')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->top] = (int) $r->net;
        }

        return $out;
    }

    /**
     * @param  list<AccountType>  $types
     * @return list<LedgerAccount>
     */
    private function parents(array $types): array
    {
        return array_values(LedgerAccount::query()->whereNull('parent_id')->whereIn('type', $types)->orderBy('code')->get()->all());
    }
}
