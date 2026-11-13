<?php

declare(strict_types=1);

namespace Modules\Billing\Gateway;

/**
 * What RightAlly needs from a payment provider (Stripe in production, a fake in tests).
 */
interface PaymentGateway
{
    /** Whether payments can be taken now (keys set for the current mode). */
    public function ready(): bool;

    /**
     * A hosted checkout page for a monthly subscription at a given price. Metadata comes back on the
     * gateway's events so the subscription can be matched.
     *
     * @param  array<string, string>  $metadata
     */
    public function subscriptionCheckout(string $email, string $productName, int $unitCents, string $interval, string $successUrl, string $cancelUrl, array $metadata, bool $automaticTax): string;

    /** Adds a one-off charge to the customer's next invoice (e.g. the brokerage's subsidies). */
    public function addInvoiceItem(string $customerRef, int $cents, string $description): void;

    /** Changes the monthly amount of an existing subscription from its next period. */
    public function changeAmount(string $subscriptionRef, int $unitCents, string $productName, string $interval): void;

    /** Creates a connected (Express) account for a brokerage to receive payouts. */
    public function createConnectAccount(string $email, string $businessName): string;

    /** Hosted onboarding where the brokerage completes identity checks and bank details. */
    public function connectOnboarding(string $account, string $refreshUrl, string $returnUrl): string;

    /** Sends money to a connected account; the key makes repeats harmless. */
    public function transfer(string $account, int $cents, string $description, string $idempotencyKey): string;

    /**
     * A one-off hosted payment on a connected account (the money goes to that account, e.g. an agent paying
     * the brokerage's invoice). Metadata comes back on the gateway's events.
     *
     * @param  array<string, string>  $metadata
     */
    public function paymentCheckout(string $connectedAccount, string $email, string $description, int $cents, string $successUrl, string $cancelUrl, array $metadata): string;

    /** Hosted page where a customer manages their card and sees invoices. */
    public function portal(string $customerRef, string $returnUrl): string;

    /** Stop a subscription at the end of the current period. */
    public function cancelAtPeriodEnd(string $subscriptionRef): void;

    /**
     * Verifies an incoming event and returns it as an array; throws when the signature is wrong.
     *
     * @return array<string, mixed>
     */
    public function verifyEvent(string $payload, string $signature): array;
}
