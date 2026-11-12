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

    /** @var list<array{customer: string, cents: int, description: string}> */
    public array $invoiceItems = [];

    /** @var list<array{subscription: string, cents: int}> */
    public array $amountChanges = [];

    public function ready(): bool
    {
        return true;
    }

    public function subscriptionCheckout(string $email, string $productName, int $unitCents, string $interval, string $successUrl, string $cancelUrl, array $metadata, bool $automaticTax): string
    {
        $this->checkouts[] = compact('email', 'productName', 'unitCents', 'interval', 'metadata', 'automaticTax');

        return 'https://checkout.example/session/'.count($this->checkouts);
    }

    public function addInvoiceItem(string $customerRef, int $cents, string $description): void
    {
        $this->invoiceItems[] = ['customer' => $customerRef, 'cents' => $cents, 'description' => $description];
    }

    public function changeAmount(string $subscriptionRef, int $unitCents, string $productName, string $interval): void
    {
        $this->amountChanges[] = ['subscription' => $subscriptionRef, 'cents' => $unitCents];
    }

    public function portal(string $customerRef, string $returnUrl): string
    {
        return 'https://portal.example/'.$customerRef;
    }

    public function cancelAtPeriodEnd(string $subscriptionRef): void
    {
        $this->cancelled[] = $subscriptionRef;
    }

    public function verifyEvent(string $payload, string $signature): array
    {
        if ($signature !== 'fake-signature') {
            throw new RuntimeException('Invalid payment event signature.');
        }

        return (array) json_decode($payload, true);
    }
}
