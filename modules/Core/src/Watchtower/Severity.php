<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

/**
 * How bad a problem is (OB1): by where it happens (signing in, money, deals and signatures, leads and messages), how
 * many brokerages it hits, and how fast it is happening. Only ever goes up; the AI can raise it further.
 */
final class Severity
{
    /** Routes, jobs and commands where a failure costs someone money, a deal or a lead, or locks them out. */
    private const SENSITIVE = '/(sign-?in|login|two-factor|password|payment|payout|billing|invoice|checkout|credit|wallet|refund|subscription|commission|ledger|disburse|transaction|deal|esign|signature|lead|messag|text|call|webhook)/i';

    public static function for(Incident $i, string $floor = 'normal'): string
    {
        $sensitive = preg_match(self::SENSITIVE, (string) $i->location) === 1;
        $hour = $i->lastHour();
        $computed = match (true) {
            $hour >= 100 && $i->brokerages >= 5, $sensitive && $i->brokerages >= 3 && $i->kind !== 'browser' => 'critical',
            $sensitive, $hour >= 20 => 'high',
            default => 'normal',
        };

        return self::max(self::max($computed, $floor), $i->severity);
    }

    public static function max(?string $a, ?string $b): string
    {
        return Incident::rank($a) >= Incident::rank($b) ? ($a ?? 'normal') : ($b ?? 'normal');
    }
}
