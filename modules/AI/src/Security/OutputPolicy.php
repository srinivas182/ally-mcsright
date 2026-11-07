<?php

declare(strict_types=1);

namespace Modules\AI\Security;

/**
 * Last line of AI data protection (specs/ai/01): an answer may only mention record IDs,
 * email addresses and phone numbers that were present in what the gateway sent.
 * Anything else is treated as a possible leak and the answer is blocked.
 */
final class OutputPolicy
{
    private const ULID = '/\b[0-7][0-9A-HJKMNP-TV-Z]{25}\b/';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    private const PHONE = '/(?:\+?1[\s.\-]?)?\(?\b\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}\b/';

    /**
     * @return list<string> kinds of identifiers that were not permitted (never the values themselves)
     */
    public function violations(string $output, string $permittedText): array
    {
        $violations = [];

        foreach ($this->matches(self::ULID, $output) as $ulid) {
            if (! str_contains($permittedText, $ulid)) {
                $violations[] = 'record_id';
            }
        }

        $permittedLower = strtolower($permittedText);
        foreach ($this->matches(self::EMAIL, $output) as $email) {
            if (! str_contains($permittedLower, strtolower($email))) {
                $violations[] = 'email';
            }
        }

        $permittedDigits = (string) preg_replace('/\D/', '', $permittedText);
        foreach ($this->matches(self::PHONE, $output) as $phone) {
            $digits = substr((string) preg_replace('/\D/', '', $phone), -10);
            if (! str_contains($permittedDigits, $digits)) {
                $violations[] = 'phone';
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @return list<string>
     */
    private function matches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $found);

        return $found[0];
    }
}
