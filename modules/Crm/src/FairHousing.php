<?php

declare(strict_types=1);

namespace Modules\Crm;

/**
 * Wording that can steer buyers or refer to protected characteristics (US Fair Housing Act).
 * Used on AI drafts and on follow-up plan templates. A guardrail, not legal advice.
 */
final class FairHousing
{
    private const FLAGS = ['safe neighborhood', 'safe neighbourhood', 'family-friendly', 'family friendly', 'good for families',
        'perfect for young', 'great for kids', 'church', 'mosque', 'synagogue', 'integrated', 'exclusive neighborhood', 'no children',
        'adults only', 'walking distance to temple', 'traditional neighborhood', 'ethnic', 'bachelor pad', 'handicap'];

    /**
     * @return list<string> the risky phrases found
     */
    public static function flags(string $text): array
    {
        $lower = mb_strtolower($text);

        return array_values(array_filter(self::FLAGS, fn (string $flag): bool => str_contains($lower, $flag)));
    }
}
