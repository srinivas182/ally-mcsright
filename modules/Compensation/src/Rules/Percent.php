<?php

declare(strict_types=1);

namespace Modules\Compensation\Rules;

use Brick\Math\BigDecimal;

/**
 * Exact percentages as decimal strings (0–100, up to 4 decimals). Never floats.
 */
final class Percent
{
    public static function isValid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value) === 1
            && BigDecimal::of($value)->isLessThanOrEqualTo(100);
    }

    /**
     * 100 minus the given percent, e.g. "80" → "20" (legacy rule 4).
     */
    public static function complement(string $percent): string
    {
        return (string) BigDecimal::of(100)->minus(BigDecimal::of($percent));
    }
}
