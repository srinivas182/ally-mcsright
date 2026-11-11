<?php

declare(strict_types=1);

namespace Modules\Compensation\Calculator;

use Modules\Ledger\Money\Money;

final readonly class DeductionLine
{
    public function __construct(
        public string $code,
        public string $name,
        public Money $amount,
        public string $payableTo,
    ) {}
}
