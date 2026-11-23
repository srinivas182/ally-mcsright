<?php

declare(strict_types=1);

namespace Modules\Sites\Support;

/**
 * LP1: the two kinds of website design. Full websites (home search, property pages and listings from an MLS board) and
 * landing pages (one polished page about the brokerage or agent, for those without an MLS board yet).
 */
final class Designs
{
    /** @return list<string> */
    public static function full(): array
    {
        return array_values(array_filter(array_keys((array) config('sites.templates')), fn ($k): bool => ! self::isLanding((string) $k)));
    }

    /** @return list<string> */
    public static function landing(): array
    {
        return array_values(array_filter(array_keys((array) config('sites.templates')), fn ($k): bool => self::isLanding((string) $k)));
    }

    public static function isLanding(?string $template): bool
    {
        return $template !== null && config("sites.templates.{$template}.kind") === 'landing';
    }

    public static function exists(?string $template): bool
    {
        return $template !== null && is_array(config("sites.templates.{$template}"));
    }
}
