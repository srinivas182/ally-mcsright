<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Reports\FinancialStatements;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Finance → Financial statements (AC2, specs/finance/21): profit and loss and the balance sheet, on screen, as PDF and CSV. */
final class StatementsController
{
    public function index(Request $request, FinancialStatements $statements): Response
    {
        Gate::authorize('finance.ledger.view');
        [$from, $to] = self::period($request);

        return Inertia::render('finance/statements', ['pnl' => $statements->profitAndLoss($from, $to), 'balance' => $statements->balanceSheet($to),
            'preset' => (string) $request->query('preset', 'ytd')]);
    }

    public function pdf(Request $request, FinancialStatements $statements, TenantContext $tenants): HttpResponse
    {
        Gate::authorize('finance.ledger.export');
        [$from, $to] = self::period($request);
        app(AuditLogger::class)->record('finance.statements_exported', metadata: ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'format' => 'pdf'], severity: 'notice');

        return Pdf::loadView('ledger::statements', ['pnl' => $statements->profitAndLoss($from, $to), 'balance' => $statements->balanceSheet($to), 'brokerage' => $tenants->current()->name])
            ->setPaper('letter')->stream('financial-statements-'.$to->toDateString().'.pdf');
    }

    public function csv(Request $request, FinancialStatements $statements): StreamedResponse
    {
        Gate::authorize('finance.ledger.export');
        [$from, $to] = self::period($request);
        $p = $statements->profitAndLoss($from, $to);
        $b = $statements->balanceSheet($to);
        $d = fn (int $c): string => number_format($c / 100, 2, '.', '');
        $rows = [['Profit and loss', $p['from'].' to '.$p['to'], 'Same period last year'], ['Income', '', '']];
        foreach ($p['revenue'] as $r) {
            $rows[] = [$r['code'].' '.$r['name'], $d($r['cents']), $d($r['prior_cents'])];
        }
        $rows[] = ['Total income', $d($p['revenue_cents']), $d($p['prior_revenue_cents'])];
        $rows[] = ['Expenses', '', ''];
        foreach ($p['expenses'] as $r) {
            $rows[] = [$r['code'].' '.$r['name'], $d($r['cents']), $d($r['prior_cents'])];
        }
        $rows[] = ['Total expenses', $d($p['expense_cents']), $d($p['prior_expense_cents'])];
        $rows[] = ['Net profit', $d($p['net_cents']), $d($p['prior_net_cents'])];
        $rows[] = ['', '', ''];
        $rows[] = ['Balance sheet', 'as of '.$b['as_of'], ''];
        foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $k => $label) {
            $rows[] = [$label, '', ''];
            foreach ($b[$k] as $r) {
                $rows[] = [trim($r['code'].' '.$r['name']), $d($r['cents']), ''];
            }
            $rows[] = ['Total '.strtolower($label), $d($b[$k.'_cents']), ''];
        }
        app(AuditLogger::class)->record('finance.statements_exported', metadata: ['from' => $p['from'], 'to' => $p['to'], 'format' => 'csv'], severity: 'notice');

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $c): string => preg_match('/^[=+\-@\t\r]/', $c) === 1 && ! is_numeric($c) ? "'".$c : $c, $row), escape: '');
            }
            fclose($out);
        }, 'financial-statements-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * ytd (this year so far), last_month, last_quarter, last_year, or custom from/to.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function period(Request $request): array
    {
        $today = CarbonImmutable::today();
        $preset = (string) $request->query('preset', 'ytd');
        if ($preset === 'custom') {
            try {
                $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->query('from'));
                $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->query('to'));
                if ($from instanceof CarbonImmutable && $to instanceof CarbonImmutable && $from->lte($to)) {
                    return [$from, $to];
                }
            } catch (\Throwable) {
                // fall through to the year so far
            }
        }

        return match ($preset) {
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_quarter' => [$today->subQuarter()->startOfQuarter(), $today->subQuarter()->endOfQuarter()->startOfDay()],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()],
            default => [$today->startOfYear(), $today],
        };
    }
}
