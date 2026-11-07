<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy;

/**
 * Host name normalisation: lower-case, no port, no trailing dot, no whitespace.
 */
final class Host
{
    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return rtrim($host, '.');
    }

    public static function platformHostFor(string $slug): string
    {
        return self::normalise($slug.'.'.(string) config('core.platform_domain'));
    }
}
