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

    /** A brokerage's back-office address (DM1: with the office suffix when websites share the domain). */
    public static function platformHostFor(string $slug): string
    {
        return self::normalise($slug.self::officeSuffix().'.'.(string) config('core.platform_domain'));
    }

    /** The suffix on back-office addresses ("-office"), or "" when the back office is at the brokerage's own name. */
    public static function officeSuffix(): string
    {
        return (string) config('core.office_suffix', '');
    }

    /** "acme", "acme.rightally.app", "acme-office.rightally.app" or a pasted link: the brokerage name in it. */
    public static function slugFromTyped(string $typed): string
    {
        $host = (string) preg_replace(['#^https?://#', '#[/?\#].*$#'], '', mb_strtolower(trim($typed)));
        $host = (string) preg_replace('#\.'.preg_quote((string) config('core.platform_domain'), '#').'$#', '', self::normalise($host));
        $suffix = self::officeSuffix();

        return $suffix !== '' && str_ends_with($host, $suffix) ? substr($host, 0, -strlen($suffix)) : $host;
    }
}
