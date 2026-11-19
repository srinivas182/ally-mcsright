<?php

declare(strict_types=1);

namespace Modules\Sites\Rendering;

use Illuminate\Support\Str;

/** Website paths for listings and market pages (specs/websites/06). */
final class Urls
{
    /** Paths the site engine owns; pages cannot use them. */
    public const RESERVED = ['homes', 'market', 'home-value', '_search', '_lead', '_preview', '_valuation', '_t', '_auth', '_me', 'robots.txt', 'sitemap.xml', 'llms.txt', 'indexnow-key.txt'];

    /** /homes/{ulid}/{address-words}: the id finds the listing, the words help people and search engines. */
    public static function listing(string $ulid, ?string $label = null): string
    {
        $slug = Str::slug((string) $label);

        return '/homes/'.$ulid.($slug !== '' ? '/'.mb_substr($slug, 0, 120) : '');
    }

    public static function market(string $slug): string
    {
        return '/market/'.$slug;
    }
}
