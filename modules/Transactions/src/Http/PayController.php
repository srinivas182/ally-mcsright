<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Connect\DealImporter;
use Modules\Transactions\Connect\SyncedPay;
use Modules\Transactions\Models\Deal;

/**
 * Close there, paid here (specs/connect/12, C5a-3): what the broker can do about a closed deal's money, in RightAlly
 * only (nothing is written back to the other app). For the owner, or anyone allowed to calculate, finalise and
 * reverse deals (compensation.calculations.manage). Every action is audited.
 */
final class PayController
{
    /** Overrides the deal's pay (agent split, fees, cap, revenue share) and pays it again with them. */
    public function override(Request $request, Deal $deal, SyncedPay $pay): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['agent_split_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'waive_fees' => ['boolean'], 'no_cap' => ['boolean'],
            'no_revenue_share' => ['boolean'], 'reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Say why, for the record.']);
        self::closed($deal);
        $overrides = array_filter(['agent_split_percent' => isset($data['agent_split_percent']) ? rtrim(rtrim(number_format((float) $data['agent_split_percent'], 2, '.', ''), '0'), '.') : null,
            'waive_fees' => (bool) ($data['waive_fees'] ?? false), 'no_cap' => (bool) ($data['no_cap'] ?? false), 'no_revenue_share' => (bool) ($data['no_revenue_share'] ?? false)],
            fn ($v) => $v !== null && $v !== false);
        $deal->forceFill(['pay_overrides' => $overrides === [] ? null : $overrides + ['reason' => trim((string) $data['reason'])]])->save();
        app(AuditLogger::class)->record('transactions.deal.pay_override', $deal, metadata: ['overrides' => $overrides, 'reason' => $data['reason']], severity: 'notice', label: $deal->street);
        $pay->pay($deal, 'Broker override: '.trim((string) $data['reason']), $user);

        return self::done($deal, $overrides === [] ? 'Overrides removed; the deal is paid on the agent\'s plan.' : 'Paid again with your overrides.');
    }

    /** Undoes the deal's payment in RightAlly: split, fees, cap and revenue share. */
    public function reverse(Request $request, Deal $deal, SyncedPay $pay): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Say why, for the record.']);
        $final = $pay->finalCalculation($deal) ?? throw ValidationException::withMessages(['reason' => 'This deal has not been paid in RightAlly.']);
        $pay->reverse($deal, $final, 'Reversed by the broker: '.trim((string) $data['reason']), $user);

        return self::done($deal, 'Reversed. It is paid again when the deal changes in '.($deal->imported_from ?? 'RightAlly').', or when you press "Pay again".');
    }

    /** Pays a reversed deal again, or one that was waiting for a fix. */
    public function again(Request $request, Deal $deal, SyncedPay $pay): RedirectResponse
    {
        $user = self::manager($request);
        self::closed($deal);
        $pay->pay($deal, 'Paid again by the broker', $user);

        return self::done($deal, $deal->money_status === 'paid' ? 'Paid.' : (string) $deal->money_note);
    }

    /** A closed deal that came over without a commission amount: the broker enters it once and it is paid. */
    public function gci(Request $request, Deal $deal, SyncedPay $pay): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['gci' => ['required', 'string', 'max:20', 'regex:/^\$?\s*[0-9][0-9,]*(\.[0-9]{1,2})?$/']],
            ['gci.required' => 'Enter the commission (GCI) for this deal.', 'gci.regex' => 'Enter the commission in dollars, like 9,500 or 9500.00.']);
        self::closed($deal);
        $cents = DealImporter::cents((string) $data['gci']);
        if ($cents <= 0 || $cents > 10_000_000_000) {
            throw ValidationException::withMessages(['gci' => 'Enter the commission in dollars, like 9,500 or 9500.00.']);
        }
        $deal->forceFill(['gci_cents' => $cents])->save();
        $pay->pay($deal, 'GCI entered by the broker', $user);

        return self::done($deal, $deal->money_status === 'paid' ? 'Paid.' : (string) $deal->money_note);
    }

    /** How agents get their own commission on deals from another app (owner only). */
    public function settings(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isKind(UserKind::Owner), 403);
        $data = $request->validate(['synced_commission' => ['required', 'in:outside,payouts']]);
        $db = DB::connection(Connections::cell());
        $tenant = app(TenantContext::class)->id();
        $db->table('tx_settings')->updateOrInsert(['tenant_id' => $tenant], ['synced_commission' => $data['synced_commission'], 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.settings.synced_commission', metadata: ['value' => $data['synced_commission']], severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. It applies to deals paid from now on.']);

        return back();
    }

    /** From which date deals that close in a connected app are paid here (owner only). */
    public function payFrom(Request $request, ConnectorKit $kit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isKind(UserKind::Owner), 403);
        $data = $request->validate(['connection' => ['required', 'string', 'size:26'], 'pay_from' => ['required', 'date']]);
        foreach (['dotloop', 'skyslope', 'lone_wolf'] as $app) {
            foreach ($kit->connections($app) as $c) {
                if ($c->ulid === $data['connection']) {
                    $kit->saveSettings($c, ['pay_from' => (string) DealImporter::date((string) $data['pay_from'])]);
                    app(AuditLogger::class)->record('transactions.settings.pay_from', metadata: ['app' => $app, 'pay_from' => $data['pay_from']], severity: 'notice');
                    Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Deals that close from that date are paid here on the next sync.']);

                    return back();
                }
            }
        }
        abort(404);
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isKind(UserKind::Owner) || $user->can('compensation.calculations.manage')), 403);

        return $user;
    }

    private static function closed(Deal $deal): void
    {
        if ($deal->status !== 'closed') {
            throw ValidationException::withMessages(['reason' => 'Only a closed deal is paid.']);
        }
    }

    private static function done(Deal $deal, string $message): RedirectResponse
    {
        $deal->refresh();
        Inertia::flash('toast', ['type' => in_array($deal->money_status, ['needs_gci', 'needs_review'], true) ? 'warning' : 'success', 'message' => $message]);

        return back();
    }
}
