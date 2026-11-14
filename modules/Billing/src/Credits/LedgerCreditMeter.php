<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use Modules\AI\Contracts\CreditMeter;
use Modules\Core\Tenancy\TenantContext;

/** The AI gateway's credit meter, backed by the brokerage's RightAlly Credits ledger (specs/billing/01). */
final class LedgerCreditMeter implements CreditMeter
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function charge(string $actionKey, ?int $userId, string $idempotencyKey): void
    {
        if (! $this->tenants->check() || $this->tenants->current()->getAttribute('type') === 'hq') {
            return; // HQ's own use is not billed
        }
        app(CreditLedger::class)->charge($actionKey, $userId, $idempotencyKey);
    }

    public function refund(string $idempotencyKey, string $reason): void
    {
        if ($this->tenants->check()) {
            app(CreditLedger::class)->refund($idempotencyKey, $reason);
        }
    }
}
