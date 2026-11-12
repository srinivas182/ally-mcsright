<?php

declare(strict_types=1);

namespace Modules\Billing\Gateway;

use Illuminate\Http\Client\Factory as Http;
use Modules\Billing\PaymentSettings;
use RuntimeException;

/**
 * Stripe over its REST API (form-encoded), with the keys of the current mode from HQ settings.
 */
final class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1/';

    private const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly Http $http, private readonly PaymentSettings $settings) {}

    public function ready(): bool
    {
        return $this->settings->keys() !== null;
    }

    public function subscriptionCheckout(string $email, string $productName, int $unitCents, string $interval, string $successUrl, string $cancelUrl, array $metadata, bool $automaticTax): string
    {
        $session = $this->post('checkout/sessions', [
            'mode' => 'subscription', 'customer_email' => $email, 'success_url' => $successUrl, 'cancel_url' => $cancelUrl,
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $unitCents,
                'recurring' => ['interval' => $interval], 'product_data' => ['name' => $productName]]]],
            'metadata' => $metadata, 'subscription_data' => ['metadata' => $metadata],
            'automatic_tax' => ['enabled' => $automaticTax ? 'true' : 'false'],
        ]);

        return (string) $session['url'];
    }

    public function portal(string $customerRef, string $returnUrl): string
    {
        return (string) $this->post('billing_portal/sessions', ['customer' => $customerRef, 'return_url' => $returnUrl])['url'];
    }

    public function cancelAtPeriodEnd(string $subscriptionRef): void
    {
        $this->post('subscriptions/'.urlencode($subscriptionRef), ['cancel_at_period_end' => 'true']);
    }

    public function verifyEvent(string $payload, string $signature): array
    {
        $secret = $this->settings->keys()['webhook_secret'] ?? null;
        $parts = [];
        foreach (explode(',', $signature) as $item) {
            [$k, $v] = array_pad(explode('=', $item, 2), 2, '');
            $parts[$k][] = $v;
        }
        $time = (int) ($parts['t'][0] ?? 0);
        $expected = $secret !== null ? hash_hmac('sha256', $time.'.'.$payload, $secret) : null;
        $valid = $expected !== null && abs(time() - $time) <= self::TOLERANCE_SECONDS
            && collect($parts['v1'] ?? [])->contains(fn (string $v): bool => hash_equals($expected, $v));
        if (! $valid) {
            throw new RuntimeException('Invalid payment event signature.');
        }

        return (array) json_decode($payload, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(string $path, array $data): array
    {
        $secret = $this->settings->keys()['secret'] ?? null;
        if ($secret === null) {
            throw new RuntimeException('Payments are not set up yet.');
        }
        $response = $this->http->asForm()->withToken($secret)->timeout(20)->post(self::API.$path, $data);
        if ($response->failed()) {
            throw new RuntimeException('The payment provider refused the request ('.$response->status().').');
        }

        return (array) $response->json();
    }
}
