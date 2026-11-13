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
use Modules\Payouts\Balances;
use Modules\Payouts\BankAccounts;
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

        return Inertia::render('finance/my-money', ['balances' => $balances->for($user), 'bank' => $accounts->current($user), 'holdHours' => BankAccounts::HOLD_HOURS]);
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
                'ulid' => $r->ulid, 'status' => $r->status, 'pay_on' => $r->pay_on, 'same_day' => (bool) $r->same_day, 'total_cents' => (int) $r->total_cents,
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
}
