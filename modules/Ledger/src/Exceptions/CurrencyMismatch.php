<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use InvalidArgumentException;

final class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(string $a, string $b): self
    {
        return new self("Cannot combine amounts in {$a} and {$b}.");
    }
}
