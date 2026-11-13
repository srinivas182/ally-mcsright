<?php

declare(strict_types=1);

namespace Modules\Payouts\Http;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;
use Modules\Payouts\AgentBilling;
use Modules\Payouts\Balances;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\GetPaidNow;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Receipts;
use Modules\Transactions\Models\Deal;

final class PayoutsController
{
    /** Finance: deals awaiting money from title, and what arrived. */
    public function receipts(Request $request, Receipts $receipts): Response
    {
        $this->finance($request);
        $only = (string) $request->query('show', 'open');
        $deals = Deal::query()->whereIn('status', ['under_contract', 'closed'])->whereNotNull('calculation_id')->orderBy('closing_date')->limit(300)->get();

        return Inertia::render('finance/receipts', [
            'show' => $only,
            'deals' => $deals->map(function (Deal $d) use ($receipts): array {
                $calc = Calculation::query()->find($d->calculation_id);
                $rows = $receipts->forDeal($d);

                return ['ulid' => $d->ulid, 'street' => $d->street, 'status' => $d->status, 'closing_date' => $d->closing_date?->toDateString(),
                    'expected_cents' => $calc !== null ? $receipts->expected($d, $calc) : 0, 'received_cents' => array_sum(array_column($rows, 'amount_cents')),
                    'settled' => $receipts->settled($d->id), 'receipts' => $rows];
            })->filter(fn (array $d): bool => match ($only) {
                'all' => true,
                'problems' => collect($d['receipts'])->contains(fn ($r) => in_array($r['status'], ['short', 'over'], true)),
                default => ! $d['settled'],
            })->values(),
        ]);
    }

    public function record(Request $request, Deal $deal, Receipts $receipts): RedirectResponse
    {
        $by = $this->finance($request);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'received_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:wire,ach,check'], 'reference' => ['nullable', 'string', 'max:60']]);
        $receipts->record($deal, (int) round((float) $data['amount'] * 100), CarbonImmutable::parse($data['received_on']), $data['method'], $data['reference'] ?? null, $by);

        return back();
    }

    public function accept(Request $request, string $receipt, Receipts $receipts): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']]);
        $receipts->accept($receipt, $data['note'], $this->finance($request));

        return back();
    }

    /** Agent: balances and payout bank account. */
    public function mine(Request $request, Balances $balances, BankAccounts $accounts): Response
    {
        $user = self::user($request);

        return Inertia::render('finance/my-money', ['balances' => $balances->for($user), 'bank' => $accounts->current($user), 'holdHours' => BankAccounts::HOLD_HOURS,
            'instant' => app(GetPaidNow::class)->quote($user)]);
    }

    public function setBank(Request $request, BankAccounts $accounts): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['holder' => ['required', 'string', 'max:120'], 'routing' => ['required', 'string', 'max:20'], 'account' => ['required', 'string', 'max:30'],
            'type' => ['required', 'in:checking,savings']]);
        $accounts->set($user, $data['holder'], $data['routing'], $data['account'], $data['type'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. For your protection, payouts go to the new account after '.BankAccounts::HOLD_HOURS.' hours.']);

        return back();
    }

    private function finance(Request $request): User
    {
        $user = self::user($request);
        abort_unless($user->can('finance.receipts.record'), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Finance: payout runs. */
    public function runs(Request $request, PayoutRuns $runs): Response
    {
        $user = $this->finance($request);
        $db = DB::connection(Connections::cell());
        $settings = $runs->settings();

        return Inertia::render('finance/payouts', [
            'runs' => $db->table('pay_runs')->orderByDesc('id')->limit(30)->get()->map(fn ($r): array => [
                'ulid' => $r->ulid, 'kind' => $r->kind, 'rail' => $r->rail, 'failed' => $db->table('pay_run_lines')->where('run_id', $r->id)->where('rail_status', 'failed')->whereNull('reversal_journal_id')->count(),
                'status' => $r->status, 'pay_on' => $r->pay_on, 'same_day' => (bool) $r->same_day, 'total_cents' => (int) $r->total_cents,
                'approved_by' => $r->approved_by !== null ? User::query()->find((int) $r->approved_by)?->name : null, 'sent_at' => $r->sent_at,
                'lines' => $db->table('pay_run_lines')->where('run_id', $r->id)->orderBy('id')->get()->map(fn ($l): array => [
                    'name' => $l->name, 'type' => $l->payee_type, 'commission_cents' => (int) $l->commission_cents, 'revenue_share_cents' => (int) $l->revenue_share_cents,
                    'referral_cents' => (int) $l->referral_cents, 'amount_cents' => (int) $l->amount_cents, 'method' => $l->method, 'status' => $l->status,
                    'reason' => $l->skip_reason, 'check' => $l->check_number])->values(),
            ]),
            'settings' => ['company_name' => $settings['company_name'], 'company_id' => $settings['company_id'], 'bank_name' => $settings['bank_name'],
                'odfi_last4' => $settings['odfi_routing'] !== null ? substr($settings['odfi_routing'], -4) : null,
                'operating_last4' => $settings['operating_account'] !== null ? substr($settings['operating_account'], -4) : null, 'next_check_number' => $settings['next_check_number']],
            'canApprove' => $user->isKind(UserKind::Owner) || $user->can('finance.payouts.approve'),
            'canChangeSettings' => $user->can('finance.bank_details.manage'),
        ]);
    }

    public function buildRun(Request $request, PayoutRuns $runs): RedirectResponse
    {
        $data = $request->validate(['pay_on' => ['required', 'date', 'after_or_equal:today'], 'same_day' => ['boolean']]);
        $runs->build(CarbonImmutable::parse($data['pay_on']), (bool) ($data['same_day'] ?? false), $this->finance($request));

        return back();
    }

    public function approveRun(Request $request, string $run, PayoutRuns $runs): RedirectResponse
    {
        $runs->approve($run, self::user($request));

        return back();
    }

    public function cancelRun(Request $request, string $run, PayoutRuns $runs): RedirectResponse
    {
        $runs->cancel($run, $this->finance($request));

        return back();
    }

    public function sendRun(Request $request, string $run, PayoutRuns $runs): RedirectResponse
    {
        $out = $runs->send($run, $this->finance($request));
        $store = Storage::disk('local');
        $seal = fn (string $v): string => Crypt::encryptString($v);
        if ($out['nacha'] !== null) {
            $store->put("payouts/{$run}/ach.enc", $seal($out['nacha']));
        }
        if ($out['positive_pay'] !== null) {
            $store->put("payouts/{$run}/positive-pay.enc", $seal($out['positive_pay']));
        }
        if ($out['checks'] !== []) {
            $pdf = Pdf::loadView('pdf.checks', ['checks' => $out['checks'], 'brokerage' => app(TenantContext::class)->current()->name])->output();
            $store->put("payouts/{$run}/checks.enc", $seal($pdf));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payouts sent. Download the bank file and checks below.']);

        return back();
    }

    public function file(Request $request, string $run, string $kind): \Symfony\Component\HttpFoundation\Response
    {
        $this->finance($request);
        $names = ['ach' => ['ach.enc', "ach-{$run}.txt", 'text/plain'], 'positive-pay' => ['positive-pay.enc', "positive-pay-{$run}.csv", 'text/csv'],
            'checks' => ['checks.enc', "checks-{$run}.pdf", 'application/pdf']];
        abort_unless(isset($names[$kind]), 404);
        $store = Storage::disk('local');
        $path = "payouts/{$run}/{$names[$kind][0]}";
        abort_unless($store->exists($path), 404);
        app(AuditLogger::class)->record('payouts.file.downloaded', metadata: ['run' => $run, 'file' => $kind], severity: 'warning');

        return response(Crypt::decryptString((string) $store->get($path)), 200,
            ['Content-Type' => $names[$kind][2], 'Content-Disposition' => 'attachment; filename="'.$names[$kind][1].'"']);
    }

    public function saveSettings(Request $request, PayoutRuns $runs): RedirectResponse
    {
        $data = $request->validate(['company_name' => ['required', 'string', 'max:16'], 'company_id' => ['required', 'string', 'max:10'], 'odfi_routing' => ['required', 'digits:9'],
            'bank_name' => ['required', 'string', 'max:23'], 'operating_account' => ['nullable', 'string', 'max:20'], 'next_check_number' => ['nullable', 'integer', 'min:1']]);
        $runs->saveSettings($data['company_name'], strtoupper($data['company_id']), $data['odfi_routing'], $data['bank_name'], $data['operating_account'] ?? null,
            isset($data['next_check_number']) ? (int) $data['next_check_number'] : null, self::user($request));

        return back();
    }

    public function getPaidNow(Request $request, GetPaidNow $instant): RedirectResponse
    {
        $data = $request->validate(['net_cents' => ['required', 'integer', 'min:1']]);
        $instant->request(self::user($request), (int) $data['net_cents']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'On its way. We\'ll email you when it\'s sent.']);

        return back();
    }

    public function instantSettings(Request $request, GetPaidNow $instant): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'flat' => ['required', 'numeric', 'min:0'], 'percent' => ['required', 'numeric', 'min:0'], 'min' => ['required', 'numeric', 'min:1']]);
        $instant->configure((bool) $data['enabled'], (int) round((float) $data['flat'] * 100), (string) $data['percent'], (int) round((float) $data['min'] * 100), self::user($request));

        return back();
    }

    public function instantLimits(Request $request, GetPaidNow $instant): RedirectResponse
    {
        $data = $request->validate(['max_flat' => ['required', 'numeric', 'min:0'], 'max_percent' => ['required', 'numeric', 'min:0', 'max:10']]);
        $instant->saveLimits((int) round((float) $data['max_flat'] * 100), (string) $data['max_percent'], self::user($request));

        return back();
    }

    /** Finance › Agent billing. */
    public function billing(Request $request, AgentBilling $billing): Response
    {
        $this->billingUser($request);
        $db = DB::connection(Connections::cell());
        $ledger = app(LedgerService::class);
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();
        $agents = User::query()->where('kind', UserKind::Agent)->orderBy('first_name')->get();

        return Inertia::render('finance/agent-billing', [
            'settings' => $billing->settings(),
            'items' => $db->table('bill_items')->where('active', true)->orderBy('name')->get(['ulid', 'name', 'amount_cents', 'frequency']),
            'agents' => $agents->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name, 'owed_cents' => $billing->owed($u->id),
                'owes_brokerage_cents' => max(0, -$ledger->balance($accounts->subAccount('2000', 'user', $u->id, 'Commission payable'))->cents)])->values(),
            'invoices' => $db->table('bill_invoices')->orderByDesc('id')->limit(100)->get()->map(fn ($i): array => ['ulid' => $i->ulid, 'number' => $i->number,
                'agent' => User::query()->find((int) $i->user_id)?->name, 'due_on' => $i->due_on, 'status' => $i->status, 'total_cents' => (int) $i->total_cents,
                'paid_cents' => (int) $i->paid_cents, 'plan' => $i->plan_id !== null])->values(),
        ]);
    }

    public function billingSettings(Request $request, AgentBilling $billing): RedirectResponse
    {
        $data = $request->validate(['deduct' => ['required', 'boolean'], 'late_fee' => ['required', 'numeric', 'min:0'], 'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'due_days' => ['required', 'integer', 'min:1', 'max:90']]);
        $billing->saveSettings((bool) $data['deduct'], (int) round((float) $data['late_fee'] * 100), (int) $data['grace_days'], (int) $data['due_days'], $this->billingUser($request));

        return back();
    }

    public function billingItem(Request $request, AgentBilling $billing): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'amount' => ['required', 'numeric', 'min:0.01'], 'frequency' => ['required', 'in:monthly,yearly,once']]);
        $billing->saveItem($data['name'], (int) round((float) $data['amount'] * 100), $data['frequency'], $this->billingUser($request));

        return back();
    }

    public function billingAssign(Request $request, AgentBilling $billing): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'item' => ['required', 'string', 'size:26'], 'from' => ['required', 'date']]);
        $billing->assign(User::query()->where('ulid', $data['agent'])->firstOrFail(), $data['item'], CarbonImmutable::parse($data['from']), $this->billingUser($request));

        return back();
    }

    public function billingCharge(Request $request, AgentBilling $billing): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'description' => ['required', 'string', 'max:160'], 'amount' => ['required', 'numeric', 'min:0.01']]);
        $billing->charge(User::query()->where('ulid', $data['agent'])->firstOrFail(), $data['description'], (int) round((float) $data['amount'] * 100), $this->billingUser($request));

        return back();
    }

    public function billingPlan(Request $request, AgentBilling $billing): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'amount' => ['required', 'numeric', 'min:0.01'], 'instalments' => ['required', 'integer', 'min:1', 'max:24'],
            'reason' => ['required', 'string', 'max:200']]);
        $billing->plan(User::query()->where('ulid', $data['agent'])->firstOrFail(), (int) round((float) $data['amount'] * 100), (int) $data['instalments'], $data['reason'], $this->billingUser($request));

        return back();
    }

    public function payInvoice(Request $request, string $invoice, AgentBilling $billing): \Symfony\Component\HttpFoundation\Response
    {
        return Inertia::location($billing->payOnline($invoice, self::user($request), $request->getSchemeAndHttpHost()));
    }

    private function billingUser(Request $request): User
    {
        $user = self::user($request);
        abort_unless($user->can('finance.billing.manage'), 403);

        return $user;
    }
}
