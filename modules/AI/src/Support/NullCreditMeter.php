<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Modules\AI\Contracts\CreditMeter;

final class NullCreditMeter implements CreditMeter
{
    public function charge(string $actionKey, ?int $userId, string $idempotencyKey): void {}

    public function refund(string $idempotencyKey, string $reason): void {}
}
