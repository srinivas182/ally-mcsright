<?php

declare(strict_types=1);

namespace Modules\AI\Security;

/**
 * Last line of AI data protection (specs/ai/01): an answer may only mention record IDs,
 * email addresses and phone numbers that were present in what the gateway sent.
 * Anything else is treated as a possible leak and the answer is blocked.
 *
 * AS1 adds the common ways attackers use a model's answer against the reader:
 * - images in the answer that load from another site (they can carry data out in the address);
 * - links to sites that were not in what the gateway sent and are not RightAlly's own (phishing);
 * - long account-style numbers that were not in what the gateway sent (wire fraud).
 */
final class OutputPolicy
{
    private const ULID = '/\b[0-7][0-9A-HJKMNP-TV-Z]{25}\b/';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    private const PHONE = '/(?:\+?1[\s.\-]?)?\(?\b\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}\b/';

    private const IMAGE = '/!\[[^\]]*\]\(\s*https?:\/\/|<img\b/i';

    private const URL = '/\bhttps?:\/\/([A-Za-z0-9.\-]+)/i';

    private const ACCOUNT = '/(?<![\d\-\/.,])\d{8,17}(?![\d\-\/.,])/';

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

        if (preg_match(self::IMAGE, $output) === 1) {
            $violations[] = 'image_link';
        }

        foreach ($this->matches(self::URL, $output) as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && ! str_contains($permittedLower, $host) && ! $this->ownHost($host)) {
                $violations[] = 'link';
            }
        }

        foreach ($this->matches(self::ACCOUNT, $output) as $number) {
            $digits = (string) preg_replace('/\D/', '', $number);
            if (strlen($digits) !== 10 && strlen($digits) !== 11 && ! str_contains($permittedDigits, $digits)) {
                $violations[] = 'account_number';
            }
        }

        return array_values(array_unique($violations));
    }

    /** RightAlly's own addresses (the platform, HQ, the public website and brokerage subdomains). */
    private function ownHost(string $host): bool
    {
        foreach (array_filter([(string) config('core.platform_domain'), (string) config('core.hq_host'), ...(array) config('marketing.hosts', [])]) as $own) {
            $own = strtolower($own);
            if ($host === $own || str_ends_with($host, '.'.$own)) {
                return true;
            }
        }

        return false;
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
