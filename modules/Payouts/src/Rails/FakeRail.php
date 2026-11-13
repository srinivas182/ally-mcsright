<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

use RuntimeException;

/** Test double: records payees and payments; accepts webhooks signed "fake". */
final class FakeRail implements PayoutRail
{
    /** @var list<array<string, mixed>> */
    public array $payees = [];

    /** @var array<string, array<string, mixed>> idempotency key => payment */
    public array $payments = [];

    public bool $failNext = false;

    public function __construct(private readonly string $name) {}

    public function key(): string
    {
        return $this->name;
    }

    public function ready(array $config): bool
    {
        return ($config['ready'] ?? true) === true;
    }

    public function payee(string $name, string $email, array $bank, array $config): string
    {
        $this->payees[] = ['name' => $name, 'last4' => substr($bank['account'], -4)];

        return 'payee-'.count($this->payees);
    }

    public function pay(string $payeeRef, int $cents, bool $sameDay, string $idempotencyKey, string $description, array $config): string
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('Partner unavailable.');
        }
        $this->payments[$idempotencyKey] ??= ['payee' => $payeeRef, 'cents' => $cents, 'same_day' => $sameDay, 'ref' => 'pay-'.(count($this->payments) + 1)];

        return (string) $this->payments[$idempotencyKey]['ref'];
    }

    public function webhook(string $payload, array $headers, ?array $config = null): array
    {
        if (($headers['x-fake-signature'] ?? '') !== 'fake') {
            throw new RuntimeException('Invalid signature.');
        }

        $out = [];
        foreach ((array) json_decode($payload, true) as $change) {
            $out[] = ['ref' => (string) ($change['ref'] ?? ''), 'status' => (string) ($change['status'] ?? ''), 'reason' => isset($change['reason']) ? (string) $change['reason'] : null];
        }

        return $out;
    }
}
