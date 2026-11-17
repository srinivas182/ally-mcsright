<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Accounting\BankReconciliation;
use Modules\Ledger\Accounting\Bills;
use Modules\Ledger\Accounting\Invoices;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Finance → Bills, Invoices and Bank reconciliation (AC1, specs/finance/20). */
final class AccountingController
{
    public function bills(Bills $bills): Response
    {
        Gate::authorize('finance.ledger.view');

        return Inertia::render('finance/bills', $bills->summary() + ['accounts' => $bills->expenseAccounts()]);
    }

    public function storeBill(Request $request, Bills $bills): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $data = $request->validate(['vendor' => ['required', 'string', 'max:160'], 'bill_number' => ['nullable', 'string', 'max:60'], 'bill_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:bill_date'], 'account_code' => ['required', 'string', 'max:25'], 'amount' => ['required', 'string', 'max:20'],
            'memo' => ['nullable', 'string', 'max:500'], 'file' => ['nullable', 'file', 'max:10240']]);
        $bills->create($data, $request->file('file'), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bill added to the books.']);

        return back();
    }

    public function payBill(Request $request, Bills $bills, string $bill): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $data = $request->validate(['paid_on' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:60']]);
        $bills->pay($bill, $data['paid_on'], $data['reference'] ?? null, self::user($request));

        return back();
    }

    public function voidBill(Request $request, Bills $bills, string $bill): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $bills->void($bill, (string) $request->validate(['reason' => ['required', 'string', 'max:150']])['reason'], self::user($request));

        return back();
    }

    public function billFile(Bills $bills, string $bill): StreamedResponse
    {
        Gate::authorize('finance.ledger.view');
        $path = $bills->file($bill) ?? abort(404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function invoices(Invoices $invoices): Response
    {
        Gate::authorize('finance.ledger.view');

        return Inertia::render('finance/invoices', $invoices->summary() + ['accounts' => $invoices->incomeAccounts()]);
    }

    public function storeInvoice(Request $request, Invoices $invoices): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $data = $request->validate(['customer' => ['required', 'string', 'max:160'], 'customer_email' => ['nullable', 'email', 'max:150'], 'customer_address' => ['nullable', 'string', 'max:300'],
            'issue_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:issue_date'], 'account_code' => ['required', 'string', 'max:25'],
            'memo' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:30'], 'lines.*.description' => ['nullable', 'string', 'max:200'],
            'lines.*.amount' => ['nullable', 'string', 'max:20']]);
        $data['lines'] = array_values(array_map(fn (array $l): array => ['description' => (string) ($l['description'] ?? ''), 'amount' => (string) ($l['amount'] ?? '0')], $data['lines']));
        $invoices->create($data, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invoice issued.']);

        return back();
    }

    public function invoicePaid(Request $request, Invoices $invoices, string $invoice): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $data = $request->validate(['paid_on' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:60']]);
        $invoices->paid($invoice, $data['paid_on'], $data['reference'] ?? null, self::user($request));

        return back();
    }

    public function voidInvoice(Request $request, Invoices $invoices, string $invoice): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $invoices->void($invoice, (string) $request->validate(['reason' => ['required', 'string', 'max:150']])['reason'], self::user($request));

        return back();
    }

    /** The invoice as a PDF to email or print. */
    public function invoicePdf(Invoices $invoices, TenantContext $tenants, string $invoice): HttpResponse
    {
        Gate::authorize('finance.ledger.view');
        $inv = $invoices->find($invoice) ?? abort(404);

        return Pdf::loadView('ledger::invoice', ['inv' => $inv, 'brokerage' => $tenants->current()->name])->setPaper('letter')
            ->stream('invoice-'.$inv['number'].'.pdf');
    }

    public function bank(Request $request, BankReconciliation $bank): Response
    {
        Gate::authorize('finance.ledger.view');
        $month = is_string($request->query('month')) && preg_match('/^\d{4}-\d{2}$/', $request->query('month')) === 1 ? $request->query('month')
            : CarbonImmutable::today()->subMonthNoOverflow()->format('Y-m');

        return Inertia::render('finance/bank', ['summary' => $bank->summary($month, self::user($request)), 'accounts' => array_merge(app(Bills::class)->expenseAccounts(), app(Invoices::class)->incomeAccounts())]);
    }

    public function importBank(Request $request, BankReconciliation $bank): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'ending_balance' => ['required', 'string', 'max:20'], 'file' => ['required', 'file', 'max:5120']]);
        $ending = (int) round((float) str_replace([',', '$', ' '], '', $data['ending_balance']) * 100);
        $bank->import($data['month'], (string) file_get_contents($request->file('file')->getRealPath()), $ending, self::user($request));

        return redirect('/finance/bank?month='.$data['month']);
    }

    public function addToBooks(Request $request, BankReconciliation $bank, int $line): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $bank->addToBooks($line, (string) $request->validate(['account_code' => ['required', 'string', 'max:25']])['account_code'], self::user($request));

        return back();
    }

    public function matchBank(Request $request, BankReconciliation $bank, int $line): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $bank->match($line, (int) $request->validate(['ledger_line_id' => ['required', 'integer']])['ledger_line_id'], self::user($request));

        return back();
    }

    public function approveBank(Request $request, BankReconciliation $bank): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $bank->approve((string) $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']])['month'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reconciliation approved and locked.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
