<?php

declare(strict_types=1);

namespace Modules\Payouts\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Rails\RailPlatform;
use Modules\Payouts\Rails\Rails;
use Modules\Payouts\Rails\RailSetup;
use Modules\Payouts\Rails\RailWebhooks;
use Throwable;

final class RailsController
{
    /** HQ: payout rails offered to brokerages. */
    public function hq(RailPlatform $platform): Response
    {
        return Inertia::render('hq/payout-rails', ['rails' => $platform->summary(), 'webhooks' => [
            'dwolla' => 'https://'.config('core.hq_host').'/payout-rails/dwolla/webhook',
            'stripe' => 'https://'.config('core.hq_host').'/payout-rails/stripe/webhook',
        ]]);
    }

    public function hqEnable(Request $request, RailPlatform $platform): RedirectResponse
    {
        $data = $request->validate(['rail' => ['required', 'in:dwolla,modern_treasury,stripe'], 'enabled' => ['required', 'boolean']]);
        $platform->setEnabled($data['rail'], (bool) $data['enabled'], self::user($request));

        return back();
    }

    public function hqKeys(Request $request, RailPlatform $platform): RedirectResponse
    {
        $data = $request->validate(['rail' => ['required', 'in:dwolla,stripe'], 'mode' => ['required', 'in:test,live'], 'key' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'], 'webhook_secret' => ['required', 'string', 'max:255']]);
        $keys = $data['rail'] === 'dwolla' ? ['key' => (string) ($data['key'] ?? ''), 'secret' => (string) ($data['secret'] ?? ''), 'webhook_secret' => $data['webhook_secret']]
            : ['webhook_secret' => $data['webhook_secret']];
        $platform->saveKeys($data['rail'], $data['mode'], $keys, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Keys are encrypted and will not be shown again.']);

        return back();
    }

    /** Brokerage: choose and link the payout rail. */
    public function choose(Request $request, RailSetup $setup): RedirectResponse
    {
        $data = $request->validate(['rail' => ['required', 'in:bank_file,dwolla,modern_treasury,stripe'], 'same_day' => ['boolean']]);
        $setup->choose($data['rail'], (bool) ($data['same_day'] ?? false), self::user($request));

        return back();
    }

    public function dwollaBusiness(Request $request, RailSetup $setup): RedirectResponse
    {
        $data = $request->validate([
            'businessName' => ['required', 'string', 'max:255'], 'businessType' => ['required', 'in:llc,corporation,partnership,soleProprietorship'],
            'ein' => ['required', 'regex:/^\d{2}-?\d{7}$/'], 'address1' => ['required', 'string', 'max:50'], 'city' => ['required', 'string', 'max:50'],
            'state' => ['required', 'size:2'], 'postalCode' => ['required', 'string', 'max:10'], 'email' => ['required', 'email'],
            'controller_first' => ['required', 'string', 'max:50'], 'controller_last' => ['required', 'string', 'max:50'], 'controller_title' => ['required', 'string', 'max:50'],
            'controller_dob' => ['required', 'date'], 'controller_ssn4' => ['required', 'digits:4'],
        ]);
        $setup->dwollaBusiness(['firstName' => $data['controller_first'], 'lastName' => $data['controller_last'], 'email' => $data['email'],
            'businessName' => $data['businessName'], 'businessType' => $data['businessType'], 'businessClassification' => '9ed3f670-7d6f-11e3-b1ce-5404a6144203',
            'ein' => $data['ein'], 'address1' => $data['address1'], 'city' => $data['city'], 'state' => strtoupper($data['state']), 'postalCode' => $data['postalCode'],
            'controller' => ['firstName' => $data['controller_first'], 'lastName' => $data['controller_last'], 'title' => $data['controller_title'],
                'dateOfBirth' => $data['controller_dob'], 'ssn' => $data['controller_ssn4'],
                'address' => ['address1' => $data['address1'], 'city' => $data['city'], 'stateProvinceRegion' => strtoupper($data['state']), 'postalCode' => $data['postalCode'], 'country' => 'US']],
        ], self::user($request));

        return back();
    }

    public function dwollaBank(Request $request, RailSetup $setup): RedirectResponse
    {
        $data = $request->validate(['routing' => ['required', 'string', 'max:20'], 'account' => ['required', 'string', 'max:30'], 'type' => ['required', 'in:checking,savings']]);
        $setup->dwollaBank($data['routing'], $data['account'], $data['type'], self::user($request));

        return back();
    }

    public function dwollaVerify(Request $request, RailSetup $setup): RedirectResponse
    {
        $data = $request->validate(['amount1' => ['required', 'numeric', 'max:1'], 'amount2' => ['required', 'numeric', 'max:1']]);
        $setup->dwollaVerify(number_format((float) $data['amount1'], 2, '.', ''), number_format((float) $data['amount2'], 2, '.', ''), self::user($request));

        return back();
    }

    public function modernTreasury(Request $request, RailSetup $setup): RedirectResponse
    {
        $data = $request->validate(['org_id' => ['required', 'string', 'max:100'], 'api_key' => ['required', 'string', 'max:255'], 'webhook_key' => ['required', 'string', 'max:255'],
            'internal_account_id' => ['required', 'string', 'max:100']]);
        $setup->modernTreasury($data['org_id'], $data['api_key'], $data['webhook_key'], $data['internal_account_id'], self::user($request));

        return back();
    }

    public function stripe(Request $request, RailSetup $setup): RedirectResponse
    {
        $setup->stripeFinancialAccount(self::user($request));

        return back();
    }

    public function retry(Request $request, string $run, PayoutRuns $runs): RedirectResponse
    {
        abort_unless(self::user($request)->can('finance.receipts.record'), 403);
        $count = $runs->submit($run);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$count} payment(s) submitted."]);

        return back();
    }

    /** Signed webhooks: Dwolla and Stripe (platform-wide), Modern Treasury (per brokerage). */
    public function webhook(Request $request, string $rail, RailWebhooks $webhooks, ?Tenant $tenant = null): JsonResponse
    {
        $headers = array_map(fn (array $v): string => (string) ($v[0] ?? ''), array_change_key_case($request->headers->all(), CASE_LOWER));
        try {
            $applied = $webhooks->handle($rail, (string) $request->getContent(), $headers, $tenant);
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        return response()->json(['applied' => $applied]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
