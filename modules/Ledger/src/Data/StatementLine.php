<?php

declare(strict_types=1);

namespace Modules\Ledger\Data;

use Carbon\CarbonImmutable;
use Modules\Ledger\Money\Money;

final readonly class StatementLine
{
    public function __construct(
        public CarbonImmutable $date,
        public string $journalUlid,
        public string $journalType,
        public ?string $description,
        public ?string $memo,
        public Money $amount,
        public Money $balance,
    ) {}
}
