<?php

declare(strict_types=1);

namespace Modules\Forms\Identity;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Validation\ValidationException;
use Modules\Billing\PaymentSettings;

/**
 * Stripe Identity on the brokerage's own Stripe account (the brokerage pays Stripe per check): a government ID
 * captured live with a matching selfie. Stripe holds the images; we read back only the outcome and the name.
 */
final class StripeIdentity implements IdentityProvider
{
    private const API = 'https://api.stripe.com/v1/identity/verification_sessions';

    public function __construct(private readonly PaymentSettings $payments, private readonly Http $http) {}

    public function name(): string
    {
        return 'Stripe Identity';
    }

    public function ready(): bool
    {
        return ($this->payments->keys()['secret'] ?? '') !== '';
    }

    public function start(string $reference, string $email, string $returnUrl): array
    {
        $response = $this->http->asForm()->withToken($this->secret())->timeout(20)->post(self::API, [
            'type' => 'document',
            'options' => ['document' => ['require_live_capture' => 'true', 'require_matching_selfie' => 'true']],
            'provided_details' => ['email' => $email],
            'metadata' => ['reference' => $reference],
            'return_url' => $returnUrl,
        ]);
        if ($response->failed() || ! is_string($response->json('url'))) {
            throw ValidationException::withMessages(['identity' => 'The identity service is not available right now. Please try again shortly.']);
        }

        return ['ref' => (string) $response->json('id'), 'url' => (string) $response->json('url')];
    }

    public function fetch(string $ref): array
    {
        $response = $this->http->withToken($this->secret())->timeout(20)
            ->get(self::API.'/'.rawurlencode($ref), ['expand' => ['verified_outputs', 'last_verification_report']]);
        if ($response->failed()) {
            return ['status' => 'pending', 'name' => null, 'document_type' => null, 'document_country' => null, 'reason' => null];
        }
        $status = (string) $response->json('status');
        $name = trim(((string) $response->json('verified_outputs.first_name')).' '.((string) $response->json('verified_outputs.last_name')));

        return [
            'status' => match ($status) {
                'verified' => 'verified',
                'requires_input', 'canceled' => 'failed',
                default => 'pending',
            },
            'name' => $name !== '' ? $name : null,
            'document_type' => is_string($response->json('last_verification_report.document.type')) ? (string) $response->json('last_verification_report.document.type') : null,
            'document_country' => is_string($response->json('last_verification_report.document.issuing_country')) ? (string) $response->json('last_verification_report.document.issuing_country') : null,
            'reason' => is_string($response->json('last_error.reason')) ? mb_substr((string) $response->json('last_error.reason'), 0, 200) : null,
        ];
    }

    private function secret(): string
    {
        $secret = $this->payments->keys()['secret'] ?? null;
        if (! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages(['identity' => 'The brokerage has not set up its identity service yet. Please contact your agent.']);
        }

        return $secret;
    }
}
