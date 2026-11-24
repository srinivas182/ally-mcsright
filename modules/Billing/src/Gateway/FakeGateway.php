<?php

declare(strict_types=1);

namespace Modules\Billing\Gateway;

use RuntimeException;

/**
 * Test double: records what would be sent and accepts events signed with "fake-signature".
 */
final class FakeGateway implements PaymentGateway
{
    /** @var list<array<string, mixed>> */
    public array $checkouts = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<string> PB4: unpaid subscriptions stopped now, their open invoices voided */
    public array $ended = [];

    /** @var list<array<string, mixed>> */
    public array $payments = [];

    /** @var list<array{customer: string, cents: int, description: string}> */
    public array $invoiceItems = [];

    /** @var list<array{subscription: string, cents: int}> */
    public array $amountChanges = [];

    /** @var array<string, array{account: string, cents: int, description: string}> idempotency key => transfer */
    public array $transfers = [];

    public function ready(): bool
    {
        return true;
    }

    /** @var list<array<string, mixed>> */
    public array $oneTime = [];

    public function oneTimeCheckout(string $email, string $description, int $cents, string $successUrl, string $cancelUrl, array $metadata, bool $automaticTax,
        ?string $customerRef = null, bool $saveCard = false): string
    {
        $this->oneTime[] = compact('email', 'description', 'cents', 'successUrl', 'cancelUrl', 'metadata', 'automaticTax', 'customerRef', 'saveCard');

        return 'https://checkout.example/pay/'.count($this->oneTime);
    }

    /** @var array<string, array{brand: string, last4: string}> customer => card */
    public array $cards = [];

    /** Next status chargeSavedCard returns (succeeded, processing, requires_action, failed). */
    public string $chargeStatus = 'succeeded';

    /** @var array<string, array{customer: string, cents: int, description: string, metadata: array<string, string>}> idempotency key => charge */
    public array $charges = [];

    public function savedCard(string $customerRef): ?array
    {
        return $this->cards[$customerRef] ?? null;
    }

    public function chargeSavedCard(string $customerRef, int $cents, string $description, array $metadata, string $idempotencyKey): array
    {
        if (! isset($this->cards[$customerRef])) {
            return ['status' => 'failed', 'payment' => '', 'error' => 'No saved card.'];
        }
        $this->charges[$idempotencyKey] ??= ['customer' => $customerRef, 'cents' => $cents, 'description' => $description, 'metadata' => $metadata];

        return ['status' => $this->chargeStatus, 'payment' => 'pi_'.substr(md5($idempotencyKey), 0, 10),
            'error' => $this->chargeStatus === 'succeeded' || $this->chargeStatus === 'processing' ? null : 'Your card was declined.'];
    }

    public function subscriptionCheckout(string $email, string $productName, int $unitCents, string $interval, string $successUrl, string $cancelUrl, array $metadata, bool $automaticTax): string
    {
        $this->checkouts[] = compact('email', 'productName', 'unitCents', 'interval', 'metadata', 'automaticTax', 'successUrl', 'cancelUrl');

        return 'https://checkout.example/session/'.count($this->checkouts);
    }

    public function createConnectAccount(string $email, string $businessName): string
    {
        return 'acct_fake_'.substr(md5($email), 0, 8);
    }

    public function connectOnboarding(string $account, string $refreshUrl, string $returnUrl): string
    {
        return 'https://connect.example/onboard/'.$account;
    }

    public function transfer(string $account, int $cents, string $description, string $idempotencyKey): string
    {
        $this->transfers[$idempotencyKey] ??= ['account' => $account, 'cents' => $cents, 'description' => $description];

        return 'tr_'.substr(md5($idempotencyKey), 0, 8);
    }

    public function addInvoiceItem(string $customerRef, int $cents, string $description): void
    {
        $this->invoiceItems[] = ['customer' => $customerRef, 'cents' => $cents, 'description' => $description];
    }

    public function changeAmount(string $subscriptionRef, int $unitCents, string $productName, string $interval): void
    {
        $this->amountChanges[] = ['subscription' => $subscriptionRef, 'cents' => $unitCents];
    }

    public function paymentCheckout(string $connectedAccount, string $email, string $description, int $cents, string $successUrl, string $cancelUrl, array $metadata): string
    {
        $this->payments[] = compact('connectedAccount', 'email', 'description', 'cents', 'metadata');

        return 'https://checkout.example/pay/'.count($this->payments);
    }

    public function portal(string $customerRef, string $returnUrl): string
    {
        return 'https://portal.example/'.$customerRef;
    }

    public function cancelAtPeriodEnd(string $subscriptionRef): void
    {
        $this->cancelled[] = $subscriptionRef;
    }

    public function endUnpaid(string $subscriptionRef): void
    {
        $this->ended[] = $subscriptionRef;
    }

    public function verifyEvent(string $payload, string $signature): array
    {
        if ($signature !== 'fake-signature') {
            throw new RuntimeException('Invalid payment event signature.');
        }

        return (array) json_decode($payload, true);
    }
}
