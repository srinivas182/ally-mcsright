<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

use Illuminate\Support\Facades\Http;

/**
 * Meta keeps ONE list of webhook fields per Page for RightAlly's app, and each subscribe call replaces it. Lead Ads
 * (leadgen), Boost forms (leadgen) and Studio replies (feed, messages) all use the same app, so each adds its fields
 * to what the Page already sends rather than replacing them (specs/grow/13, SA5c).
 */
final class MetaPageSubscriptions
{
    public const GRAPH = 'https://graph.facebook.com/v21.0/';

    /**
     * @param  list<string>  $fields
     * @return bool whether the Page now sends them
     */
    public static function add(string $page, string $pageToken, array $fields): bool
    {
        $have = self::current($page, $pageToken);
        if ($have === null) {
            return false; // without knowing what the Page sends now, changing it could switch something off
        }
        $all = array_values(array_unique([...$have, ...$fields]));
        sort($all);

        return Http::asForm()->timeout(20)->post(self::GRAPH.$page.'/subscribed_apps', ['subscribed_fields' => implode(',', $all), 'access_token' => $pageToken])->successful();
    }

    /**
     * Stops these fields only; the Page stops sending anything to RightAlly's app once none are left.
     *
     * @param  list<string>  $fields
     */
    public static function remove(string $page, string $pageToken, array $fields): void
    {
        $left = array_values(array_diff(self::current($page, $pageToken) ?? [], $fields));
        sort($left);
        $left === [] ? Http::timeout(10)->delete(self::GRAPH.$page.'/subscribed_apps', ['access_token' => $pageToken])
            : Http::asForm()->timeout(10)->post(self::GRAPH.$page.'/subscribed_apps', ['subscribed_fields' => implode(',', $left), 'access_token' => $pageToken]);
    }

    /** @return list<string>|null the fields the Page sends now (null: couldn't tell) */
    private static function current(string $page, string $pageToken): ?array
    {
        $now = Http::timeout(20)->get(self::GRAPH.$page.'/subscribed_apps', ['access_token' => $pageToken]);
        if (! $now->successful()) {
            return null;
        }
        $have = [];
        foreach ((array) $now->json('data', []) as $app) {
            if (is_array($app)) { // a Page token only lists RightAlly's own app
                foreach ((array) ($app['subscribed_fields'] ?? []) as $f) {
                    $have[] = (string) $f;
                }
            }
        }

        return $have;
    }
}
