<?php

declare(strict_types=1);

namespace Modules\Forms\Identity;

/** A government-ID + live-selfie verification service. RightAlly never receives the ID images. */
interface IdentityProvider
{
    public function name(): string;

    /** Whether the brokerage has what this service needs (for example its Stripe keys). */
    public function ready(): bool;

    /**
     * Starts a verification and returns where to send the person.
     *
     * @return array{ref: string, url: string}
     */
    public function start(string $reference, string $email, string $returnUrl): array;

    /**
     * @return array{status: 'pending'|'verified'|'failed', name: ?string, document_type: ?string, document_country: ?string, reason: ?string}
     */
    public function fetch(string $ref): array;
}
