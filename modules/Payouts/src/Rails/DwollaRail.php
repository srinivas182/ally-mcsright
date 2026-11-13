<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Dwolla (specs/finance/02): the brokerage is a verified business customer under RightAlly's Dwolla
 * platform with its own bank as the funding source; agents are receive-only customers. Transfers go from
 * the brokerage's bank to the agent's bank; Dwolla is the regulated party.
 */
final class DwollaRail implements PayoutRail
{
    private const MEDIA = 'application/vnd.dwolla.v1.hal+json';

    public function __construct(private readonly Http $http, private readonly RailPlatform $platform) {}

    public function key(): string
    {
        return 'dwolla';
    }

    public function ready(array $config): bool
    {
        return $this->platform->keys('dwolla') !== null && ! empty($config['funding_source']) && ($config['verified'] ?? false) === true;
    }

    public function payee(string $name, string $email, array $bank, array $config): string
    {
        [$first, $last] = array_pad(explode(' ', trim($name), 2), 2, '-');
        $customer = $this->post('/customers', ['firstName' => $first, 'lastName' => $last, 'email' => $email, 'type' => 'receive-only']);

        return $this->post($customer.'/funding-sources', ['routingNumber' => $bank['routing'], 'accountNumber' => $bank['account'],
            'bankAccountType' => $bank['type'], 'name' => mb_substr($bank['holder'].' payouts', 0, 50)], absolute: true);
    }

    public function pay(string $payeeRef, int $cents, bool $sameDay, string $idempotencyKey, string $description, array $config): string
    {
        $body = ['_links' => ['source' => ['href' => (string) $config['funding_source']], 'destination' => ['href' => $payeeRef]],
            'amount' => ['currency' => 'USD', 'value' => number_format($cents / 100, 2, '.', '')], 'metadata' => ['note' => mb_substr($description, 0, 255)]];
        if ($sameDay) {
            $body['clearing'] = ['destination' => 'next-available'];
        }

        return $this->post('/transfers', $body, $idempotencyKey);
    }

    /**
     * Brokerage onboarding: a verified business customer and its own bank account (micro-deposit verified).
     *
     * @param  array<string, mixed>  $details
     */
    public function businessCustomer(array $details): string
    {
        return $this->post('/customers', $details + ['type' => 'business']);
    }

    public function fundingSource(string $customer, string $routing, string $account, string $type, string $name): string
    {
        $fs = $this->post($customer.'/funding-sources', ['routingNumber' => $routing, 'accountNumber' => $account, 'bankAccountType' => $type, 'name' => $name], absolute: true);
        $this->post($fs.'/micro-deposits', [], absolute: true);

        return $fs;
    }

    public function verifyMicroDeposits(string $fundingSource, string $amount1, string $amount2): void
    {
        $this->post($fundingSource.'/micro-deposits', ['amount1' => ['value' => $amount1, 'currency' => 'USD'], 'amount2' => ['value' => $amount2, 'currency' => 'USD']], absolute: true);
    }

    public function webhook(string $payload, array $headers, ?array $config = null): array
    {
        $secret = $this->platform->keys('dwolla')['webhook_secret'] ?? null;
        $given = $headers['x-request-signature-sha-256'] ?? '';
        if ($secret === null || ! hash_equals(hash_hmac('sha256', $payload, $secret), $given)) {
            throw new RuntimeException('Invalid Dwolla signature.');
        }
        $event = (array) json_decode($payload, true);
        $topic = (string) ($event['topic'] ?? '');
        $ref = (string) ($event['_links']['resource']['href'] ?? '');
        $status = match (true) {
            str_contains($topic, 'transfer_completed') => 'completed',
            str_contains($topic, 'transfer_failed'), str_contains($topic, 'transfer_cancelled') => 'failed',
            default => null,
        };

        return $status === null || $ref === '' ? [] : [['ref' => $ref, 'status' => $status, 'reason' => $status === 'failed' ? str_replace('_', ' ', $topic) : null]];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body, ?string $idempotencyKey = null, bool $absolute = false): string
    {
        $client = $this->client();
        if ($idempotencyKey !== null) {
            $client = $client->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }
        $response = $client->post($absolute ? $path : $this->base().$path, $body);
        if ($response->failed()) {
            throw new RuntimeException('Dwolla refused the request ('.$response->status().').');
        }

        return (string) ($response->header('Location') !== '' ? $response->header('Location') : ($response->json('_links.self.href') ?? ''));
    }

    private function client(): PendingRequest
    {
        $keys = $this->platform->keys('dwolla') ?? throw new RuntimeException('Dwolla is not set up by RightAlly HQ.');
        $token = $this->http->asForm()->withBasicAuth($keys['key'], $keys['secret'])->post($this->base().'/token', ['grant_type' => 'client_credentials'])->json('access_token');

        return $this->http->withToken((string) $token)->withHeaders(['Accept' => self::MEDIA, 'Content-Type' => self::MEDIA])->timeout(20);
    }

    private function base(): string
    {
        return $this->platform->mode('dwolla') === 'live' ? 'https://api.dwolla.com' : 'https://api-sandbox.dwolla.com';
    }
}
