<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Exceptions\InsufficientCredits;

/**
 * Charges RightAlly Credits for an AI request (specs/core/07). Implemented by the credits
 * ledger in Sprints 11-13; NullCreditMeter until then.
 */
interface CreditMeter
{
    /**
     * Charge once per idempotency key, before any model is called.
     *
     * @throws InsufficientCredits
     */
    public function charge(string $actionKey, ?int $userId, string $idempotencyKey): void;

    /**
     * Return the credits for a request that produced no usable answer.
     */
    public function refund(string $idempotencyKey, string $reason): void;
}
