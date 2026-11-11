<?php

declare(strict_types=1);

namespace Modules\Compensation\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class CalculationFinalised
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly string $calculationUlid) {}
}
