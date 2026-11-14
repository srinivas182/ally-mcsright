<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

/**
 * How many billable segments a text uses: 160 characters in one GSM-7 segment (153 each when split), or 70 when it
 * needs Unicode (67 each when split). Extended GSM characters (such as € or [) count twice.
 */
final class TextSegments
{
    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    public static function count(string $message): int
    {
        if ($message === '') {
            return 1;
        }
        $length = 0;
        foreach (mb_str_split($message) as $char) {
            if (mb_strpos(self::GSM, $char) !== false) {
                $length++;
            } elseif (mb_strpos(self::GSM_EXTENDED, $char) !== false) {
                $length += 2;
            } else {
                $units = mb_strlen($message);

                return $units <= 70 ? 1 : (int) ceil($units / 67);
            }
        }

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }
}
