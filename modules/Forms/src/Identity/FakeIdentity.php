<?php

declare(strict_types=1);

namespace Modules\Forms\Identity;

/** For tests and local development: returns straight to the signer with a chosen outcome. */
final class FakeIdentity implements IdentityProvider
{
    /** @var array{status: 'pending'|'verified'|'failed', name: ?string, document_type: ?string, document_country: ?string, reason: ?string} */
    public array $next = ['status' => 'verified', 'name' => null, 'document_type' => 'driving_license', 'document_country' => 'US', 'reason' => null];

    public int $started = 0;

    public bool $isReady = true;

    public function name(): string
    {
        return 'Test identity service';
    }

    public function ready(): bool
    {
        return $this->isReady;
    }

    public function start(string $reference, string $email, string $returnUrl): array
    {
        $this->started++;

        return ['ref' => 'fake_'.$reference, 'url' => $returnUrl];
    }

    public function fetch(string $ref): array
    {
        return $this->next;
    }
}
