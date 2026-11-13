<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Modern Treasury (specs/finance/02): the brokerage's own Modern Treasury organisation and its own bank
 * account (internal account); agents become counterparties; ACH payment orders, high priority for
 * same-day. Credentials belong to the brokerage and are stored encrypted in its settings.
 */
final class ModernTreasuryRail implements PayoutRail
{
    private const BASE = 'https://app.moderntreasury.com/api';

    public function __construct(private readonly Http $http) {}

    public function key(): string
    {
        return 'modern_treasury';
    }

    public function ready(array $config): bool
    {
        return ! empty($config['org_id']) && ! empty($config['api_key']) && ! empty($config['internal_account_id']) && ! empty($config['webhook_key']);
    }

    public function payee(string $name, string $email, array $bank, array $config): string
    {
        $response = $this->client($config)->post(self::BASE.'/counterparties', ['name' => $name, 'email' => $email, 'accounts' => [[
            'account_type' => $bank['type'], 'routing_details' => [['routing_number' => $bank['routing'], 'routing_number_type' => 'aba']],
            'account_details' => [['account_number' => $bank['account']]],
        ]]]);
        if ($response->failed()) {
            throw new RuntimeException('Modern Treasury refused the payee ('.$response->status().').');
        }

        return (string) $response->json('accounts.0.id');
    }

    public function pay(string $payeeRef, int $cents, bool $sameDay, string $idempotencyKey, string $description, array $config): string
    {
        $response = $this->client($config)->withHeaders(['Idempotency-Key' => $idempotencyKey])->post(self::BASE.'/payment_orders', [
            'type' => 'ach', 'direction' => 'credit', 'amount' => $cents, 'currency' => 'USD', 'priority' => $sameDay ? 'high' : 'normal',
            'originating_account_id' => (string) $config['internal_account_id'], 'receiving_account_id' => $payeeRef, 'description' => mb_substr($description, 0, 140),
        ]);
        if ($response->failed()) {
            throw new RuntimeException('Modern Treasury refused the payment ('.$response->status().').');
        }

        return (string) $response->json('id');
    }

    public function webhook(string $payload, array $headers, ?array $config = null): array
    {
        $key = (string) ($config['webhook_key'] ?? '');
        if ($key === '' || ! hash_equals(hash_hmac('sha256', $payload, $key), $headers['x-signature'] ?? '')) {
            throw new RuntimeException('Invalid Modern Treasury signature.');
        }
        $event = (array) json_decode($payload, true);
        $status = match ((string) ($event['event'] ?? '')) {
            'completed' => 'completed', 'failed', 'cancelled' => 'failed', 'returned' => 'returned', default => null,
        };
        $ref = (string) ($event['data']['id'] ?? '');

        return $status === null || $ref === '' ? [] : [['ref' => $ref, 'status' => $status, 'reason' => $event['data']['return']['reason'] ?? ($status !== 'completed' ? $status : null)]];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(array $config): PendingRequest
    {
        return $this->http->withBasicAuth((string) ($config['org_id'] ?? ''), (string) ($config['api_key'] ?? ''))->acceptJson()->timeout(20);
    }
}
