<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

use App\Models\User;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Validation\ValidationException;
use Modules\Billing\PaymentSettings;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Payouts\BankAccounts;

/**
 * A brokerage choosing and linking its payout rail from its back office (specs/finance/02). The brokerage
 * verifies its business and links its own bank through the partner; its banking login is never given to
 * RightAlly. Business identity details pass through to the partner and are not stored by RightAlly.
 */
final class RailSetup
{
    public function __construct(private readonly Rails $rails, private readonly RailPlatform $platform, private readonly TenantContext $tenants) {}

    public function choose(string $rail, bool $sameDayDefault, User $by): void
    {
        $this->guard($by);
        if ($rail !== 'bank_file' && ! $this->platform->enabled($rail)) {
            throw ValidationException::withMessages(['rail' => 'RightAlly has not switched this payout option on yet.']);
        }
        $current = $this->rails->settings();
        $config = $current['rail'] === $rail ? $current['config'] : [];
        $ready = $rail === 'bank_file' || $this->rails->get($rail)->ready($config + $this->stripeAccount($rail));
        $this->rails->save($this->tenants->id(), $rail, $config, $sameDayDefault, $ready ? 'ready' : 'pending');
        app(AuditLogger::class)->record('payouts.rail.chosen', metadata: ['rail' => $rail], severity: 'warning');
    }

    /**
     * Dwolla, step 1: the business is verified by Dwolla (details pass through, not stored).
     *
     * @param  array<string, mixed>  $business
     */
    public function dwollaBusiness(array $business, User $by): void
    {
        $this->guard($by, 'dwolla');
        $customer = app(DwollaRail::class)->businessCustomer($business);
        $this->merge(['customer' => $customer, 'verified' => false]);
        app(AuditLogger::class)->record('payouts.rail.dwolla_business', metadata: ['business' => $business['businessName'] ?? null], severity: 'warning');
    }

    /** Dwolla, step 2: link the brokerage's own operating account; Dwolla sends two small deposits. */
    public function dwollaBank(string $routing, string $account, string $type, User $by): void
    {
        $this->guard($by, 'dwolla');
        $routing = preg_replace('/\D/', '', $routing) ?? '';
        if (! BankAccounts::validRouting($routing)) {
            throw ValidationException::withMessages(['routing' => 'That routing number is not valid.']);
        }
        $config = $this->rails->settings()['config'];
        if (empty($config['customer'])) {
            throw ValidationException::withMessages(['rail' => 'Verify the business first.']);
        }
        $fs = app(DwollaRail::class)->fundingSource((string) $config['customer'], $routing, preg_replace('/\D/', '', $account) ?? '', $type, 'Operating account');
        $this->merge(['funding_source' => $fs, 'verified' => false]);
    }

    /** Dwolla, step 3: confirm the two small deposits; the rail is then ready. */
    public function dwollaVerify(string $amount1, string $amount2, User $by): void
    {
        $this->guard($by, 'dwolla');
        $config = $this->rails->settings()['config'];
        app(DwollaRail::class)->verifyMicroDeposits((string) ($config['funding_source'] ?? ''), $amount1, $amount2);
        $this->merge(['verified' => true]);
    }

    /** Modern Treasury: the brokerage's own organisation (encrypted; never shown again). */
    public function modernTreasury(string $orgId, string $apiKey, string $webhookKey, string $internalAccountId, User $by): void
    {
        $this->guard($by, 'modern_treasury');
        if (trim($orgId) === '' || trim($apiKey) === '' || trim($webhookKey) === '' || trim($internalAccountId) === '') {
            throw ValidationException::withMessages(['rail' => 'Give your Modern Treasury organisation ID, API key, webhook key and the bank account (internal account) to pay from.']);
        }
        $this->merge(['org_id' => trim($orgId), 'api_key' => trim($apiKey), 'webhook_key' => trim($webhookKey), 'internal_account_id' => trim($internalAccountId)]);
        app(AuditLogger::class)->record('payouts.rail.modern_treasury', metadata: ['org_id' => $orgId], severity: 'critical');
    }

    /** Stripe: open a Treasury financial account on the brokerage's own connected account. */
    public function stripeFinancialAccount(User $by): void
    {
        $this->guard($by, 'stripe');
        $account = $this->stripeAccount('stripe')['account'] ?? null;
        $secret = app(PaymentSettings::class)->keys()['secret'] ?? null;
        if ($account === null || $secret === null) {
            throw ValidationException::withMessages(['rail' => 'Connect the brokerage to Stripe first (Partner earnings › Connect with Stripe).']);
        }
        $response = app(Http::class)->asForm()->withToken($secret)->withHeaders(['Stripe-Account' => $account])->timeout(20)
            ->post('https://api.stripe.com/v1/treasury/financial_accounts', ['supported_currencies' => ['usd'], 'features' => ['outbound_payments' => ['ach' => ['requested' => 'true']]]]);
        if ($response->failed()) {
            throw ValidationException::withMessages(['rail' => 'Stripe could not open the financial account ('.$response->status().').']);
        }
        $this->merge(['financial_account' => (string) $response->json('id')]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function merge(array $values): void
    {
        $s = $this->rails->settings();
        $config = $values + $s['config'];
        $ready = $this->rails->get($s['rail'])->ready($config + $this->stripeAccount($s['rail']));
        $this->rails->save($this->tenants->id(), $s['rail'], $config, $s['same_day_default'], $ready ? 'ready' : 'pending');
    }

    /**
     * @return array<string, string>
     */
    private function stripeAccount(string $rail): array
    {
        $account = $this->tenants->current()->getAttribute('connect_account');

        return $rail === 'stripe' && is_string($account) ? ['account' => $account] : [];
    }

    private function guard(User $by, ?string $rail = null): void
    {
        if (! $by->can('finance.bank_details.manage')) {
            throw ValidationException::withMessages(['rail' => 'Setting up payouts needs the bank-details permission.']);
        }
        if ($rail !== null && $this->rails->settings()['rail'] !== $rail) {
            throw ValidationException::withMessages(['rail' => 'Choose this payout option first.']);
        }
    }
}
