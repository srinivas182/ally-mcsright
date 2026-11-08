<?php

declare(strict_types=1);

namespace Modules\Ledger\Data;

use Carbon\CarbonImmutable;
use Modules\Ledger\Money\Money;

/**
 * An account statement for a date range: opening balance, each entry with a running balance,
 * and closing balance, all in the account's natural sign.
 */
final readonly class Statement
{
    /**
     * @param  list<StatementLine>  $lines
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public Money $opening,
        public array $lines,
        public Money $closing,
    ) {}
}
