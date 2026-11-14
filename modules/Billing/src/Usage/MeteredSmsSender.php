<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use Modules\Core\Messaging\SmsSender;
use Modules\Core\Tenancy\TenantContext;

/**
 * Counts each brokerage's texts against its monthly allowance (specs/billing/03). Messages a person sends from the
 * inbox (deliver) are held back when the allowance is used and credits run out while credits are enforced; system
 * messages (security codes, lead and deadline alerts) always go out.
 */
final class MeteredSmsSender implements SmsSender
{
    public function __construct(private readonly SmsSender $inner, private readonly TenantContext $tenants) {}

    public function send(string $to, string $message): void
    {
        $this->inner->send($to, $message);
        $this->count($message);
    }

    public function deliver(string $from, string $to, string $message): string
    {
        if ($this->metered()) {
            app(UsageMeter::class)->ensureAvailable('texts', TextSegments::count($message));
        }
        $id = $this->inner->deliver($from, $to, $message);
        $this->count($message);

        return $id;
    }

    private function count(string $message): void
    {
        if ($this->metered()) {
            app(UsageMeter::class)->record('texts', TextSegments::count($message));
        }
    }

    private function metered(): bool
    {
        return $this->tenants->check() && ! $this->tenants->current()->isHq();
    }
}
