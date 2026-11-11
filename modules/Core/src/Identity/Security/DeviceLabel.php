<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Security;

/**
 * A readable device label ("Chrome on Windows") from a user agent, and a stable fingerprint per
 * user (browser + operating system; not IP, which changes constantly on phones).
 */
final class DeviceLabel
{
    public static function from(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'an unknown device',
        };

        return "{$browser} on {$os}";
    }

    public static function fingerprint(int $userId, ?string $userAgent): string
    {
        return hash('sha256', $userId.'|'.self::from($userAgent));
    }
}
