<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;

/** The website's main and footer menus (specs/websites/08): up to 10 links each, to site pages or https links. */
final class Menus
{
    public const MAX = 10;

    /**
     * @param  array<string, mixed>  $in  {main: [{label, url}], footer: [{label, url}]}
     */
    public function save(Site $site, array $in): void
    {
        $menus = [];
        foreach (['main', 'footer'] as $which) {
            $items = [];
            foreach (array_slice(array_values(array_filter((array) ($in[$which] ?? []), 'is_array')), 0, self::MAX) as $i => $item) {
                $label = mb_substr(trim(strip_tags((string) ($item['label'] ?? ''))), 0, 40);
                $url = trim((string) ($item['url'] ?? ''));
                if ($label === '' || (preg_match('#^/[A-Za-z0-9/_\-?=&%.]{0,190}$#', $url) !== 1 && preg_match('#^https://[^\s"<>]{1,190}$#', $url) !== 1)) {
                    throw ValidationException::withMessages(["{$which}.{$i}" => 'Each link needs a label and a page (starting with /) or an https link.']);
                }
                $items[] = ['label' => $label, 'url' => $url];
            }
            $menus[$which] = $items;
        }
        $site->forceFill(['menus' => $menus])->save();
        app(AuditLogger::class)->record('sites.menus_saved', $site);
    }

    /**
     * The default main menu when the brokerage has not set one.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function defaults(Site $site): array
    {
        $chrome = Chrome::for($site, $site->tenant_id);

        return array_map(fn (array $m): array => ['label' => $m['title'], 'url' => $m['path']], $chrome['menu']);
    }
}
