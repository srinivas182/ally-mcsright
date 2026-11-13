<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

/**
 * A way to move the brokerage's money to payees (specs/finance/02). The brokerage's own funds move from
 * its own account; RightAlly only instructs. Every payment carries an idempotency key so a retry can
 * never pay twice.
 */
interface PayoutRail
{
    public function key(): string;

    /**
     * @param  array<string, mixed>  $config  the brokerage's rail settings
     */
    public function ready(array $config): bool;

    /**
     * Registers the payee's bank account with the partner and returns its reference.
     *
     * @param  array{routing: string, account: string, type: string, holder: string}  $bank
     * @param  array<string, mixed>  $config
     */
    public function payee(string $name, string $email, array $bank, array $config): string;

    /**
     * Sends one payment and returns the partner's reference.
     *
     * @param  array<string, mixed>  $config
     */
    public function pay(string $payeeRef, int $cents, bool $sameDay, string $idempotencyKey, string $description, array $config): string;

    /**
     * Verifies a webhook and returns the payment status changes it reports.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $config  the brokerage's settings, when the webhook is per brokerage
     * @return list<array{ref: string, status: string, reason: string|null}>
     */
    public function webhook(string $payload, array $headers, ?array $config = null): array;
}
