<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

use Illuminate\Http\Client\Factory as Http;
use Modules\Billing\PaymentSettings;
use RuntimeException;

/**
 * Stripe (specs/finance/02): outbound payments from the brokerage's own Stripe Treasury financial account
 * on its connected account to each agent's bank account. The money never passes through RightAlly's
 * balance. Same-day is not offered by this rail (standard ACH).
 */
final class StripeTreasuryRail implements PayoutRail
{
    private const API = 'https://api.stripe.com/v1/';

    public function __construct(private readonly Http $http, private readonly PaymentSettings $settings, private readonly RailPlatform $platform) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function ready(array $config): bool
    {
        return $this->settings->keys() !== null && ! empty($config['account']) && ! empty($config['financial_account']);
    }

    public function payee(string $name, string $email, array $bank, array $config): string
    {
        $customer = $this->post('customers', ['name' => $name, 'email' => $email], $config)['id'];
        $method = $this->post('payment_methods', ['type' => 'us_bank_account', 'billing_details' => ['name' => $name],
            'us_bank_account' => ['routing_number' => $bank['routing'], 'account_number' => $bank['account'], 'account_holder_type' => 'individual', 'account_type' => $bank['type']]], $config)['id'];
        $this->post('payment_methods/'.$method.'/attach', ['customer' => $customer], $config);

        return $customer.'|'.$method;
    }

    public function pay(string $payeeRef, int $cents, bool $sameDay, string $idempotencyKey, string $description, array $config): string
    {
        [$customer, $method] = explode('|', $payeeRef, 2);

        return (string) $this->post('treasury/outbound_payments', ['financial_account' => (string) $config['financial_account'], 'amount' => $cents, 'currency' => 'usd',
            'customer' => $customer, 'destination_payment_method' => $method, 'description' => mb_substr($description, 0, 500), 'statement_descriptor' => 'COMMISSION'],
            $config, $idempotencyKey)['id'];
    }

    public function webhook(string $payload, array $headers, ?array $config = null): array
    {
        $secret = $this->platform->keys('stripe')['webhook_secret'] ?? null;
        $parts = [];
        foreach (explode(',', $headers['stripe-signature'] ?? '') as $item) {
            [$k, $v] = array_pad(explode('=', $item, 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int) ($parts['t'][0] ?? 0);
        $expected = $secret !== null ? hash_hmac('sha256', $t.'.'.$payload, $secret) : null;
        if ($expected === null || abs(time() - $t) > 300 || ! collect($parts['v1'] ?? [])->contains(fn (string $v): bool => hash_equals($expected, $v))) {
            throw new RuntimeException('Invalid Stripe signature.');
        }
        $event = (array) json_decode($payload, true);
        $status = match ((string) ($event['type'] ?? '')) {
            'treasury.outbound_payment.posted' => 'completed', 'treasury.outbound_payment.failed', 'treasury.outbound_payment.canceled' => 'failed',
            'treasury.outbound_payment.returned' => 'returned', default => null,
        };
        $object = (array) ($event['data']['object'] ?? []);

        return $status === null ? [] : [['ref' => (string) ($object['id'] ?? ''), 'status' => $status,
            'reason' => isset($object['returned_details']['code']) ? (string) $object['returned_details']['code'] : ($status !== 'completed' ? $status : null)]];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, array $config, ?string $idempotencyKey = null): array
    {
        $secret = $this->settings->keys()['secret'] ?? throw new RuntimeException('Stripe is not set up by RightAlly HQ.');
        $headers = ['Stripe-Account' => (string) $config['account']];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $response = $this->http->asForm()->withToken($secret)->withHeaders($headers)->timeout(20)->post(self::API.$path, $body);
        if ($response->failed()) {
            throw new RuntimeException('Stripe refused the request ('.$response->status().').');
        }

        return (array) $response->json();
    }
}
