<?php

declare(strict_types=1);

namespace Modules\Payouts;

/** Dollar amounts in words for checks: 1234.50 → "One thousand two hundred thirty-four and 50/100". */
final class Words
{
    private const ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
        'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    public static function dollars(int $cents): string
    {
        $dollars = intdiv($cents, 100);
        $words = $dollars === 0 ? 'zero' : trim(self::chunk($dollars));

        return ucfirst($words).' and '.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).'/100';
    }

    private static function chunk(int $n): string
    {
        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'] as $size => $name) {
            if ($n >= $size) {
                return self::chunk(intdiv($n, $size)).' '.$name.($n % $size > 0 ? ' '.self::chunk($n % $size) : '');
            }
        }
        if ($n >= 100) {
            return self::ONES[intdiv($n, 100)].' hundred'.($n % 100 > 0 ? ' '.self::chunk($n % 100) : '');
        }
        if ($n >= 20) {
            return self::TENS[intdiv($n, 10)].($n % 10 > 0 ? '-'.self::ONES[$n % 10] : '');
        }

        return self::ONES[$n];
    }
}
