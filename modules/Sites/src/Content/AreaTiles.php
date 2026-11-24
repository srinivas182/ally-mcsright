<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Listings\Samples\SampleHomes;
use Modules\Listings\Search\MarketStats;

/**
 * UX1: the "areas we serve" tiles on a website's home page, like the leading brokerage sites: a photo of each city, its
 * name, and (when the MLS feed has them) homes for sale and the median price. The brokerage's own areas come first;
 * every state where it has an MLS board adds that state's main cities, so a brokerage on two boards shows both.
 * A tile always shows, even before any listings arrive.
 */
final class AreaTiles
{
    private const MAX = 8;

    /**
     * @param  list<string>  $places  place slugs such as "houston-tx"
     * @return list<array{name: string, href: string, photo: string|null, line: string|null, fine: string|null}>
     */
    public static function build(int $tenantId, array $places): array
    {
        $places = array_values(array_unique(array_map('strtolower', $places)));
        foreach (self::boardStates($tenantId) as $state) {
            if (! collect($places)->contains(fn (string $p): bool => str_ends_with($p, '-'.strtolower($state)))) {
                $places = [...$places, ...array_slice(StarterSite::places(['state' => $state]), 0, 4)];
            }
        }
        $stats = app(MarketStats::class);
        $out = [];
        foreach (array_slice(array_values(array_unique($places)), 0, self::MAX) as $i => $slug) {
            $m = $stats->forPlace($tenantId, $slug);
            [$city, $state] = self::split($slug);
            $name = is_array($m) ? $m['name'] : trim($city.($state !== '' ? ', '.$state : ''));
            $group = 'city_'.Str::snake(Str::lower($city));
            $photo = isset(SampleHomes::photoGroups()[$group]) ? DemoPhotos::pick($group, $i) : DemoPhotos::pick('neighbourhood', $i);
            $active = is_array($m) ? (int) $m['active'] : 0;
            $out[] = ['name' => $name, 'href' => $active > 0 ? '/market/'.$slug : '/homes?q='.rawurlencode($name),
                'photo' => DemoPhotos::url($photo, 'card'),
                'line' => $active > 0 ? number_format($active).' homes for sale' : 'See homes for sale',
                'fine' => is_array($m) && ($m['median_price'] ?? null) ? 'Median $'.number_format((int) $m['median_price']) : null];
        }

        return $out;
    }

    /** @return array{0: string, 1: string} "houston-tx" → ["Houston", "TX"] */
    private static function split(string $slug): array
    {
        if (preg_match('/^(.+)-([a-z]{2})$/', $slug, $m) === 1) {
            return [Str::title(str_replace('-', ' ', $m[1])), strtoupper($m[2])];
        }

        return [Str::title(str_replace('-', ' ', $slug)), ''];
    }

    /** @return list<string> the states of the brokerage's MLS boards (not cancelled) */
    private static function boardStates(int $tenantId): array
    {
        try {
            $db = DB::connection(Connections::control());
            $boards = $db->table('idx_subscriptions')->join('idx_boards', 'idx_boards.id', '=', 'idx_subscriptions.board_id')
                ->where('idx_subscriptions.tenant_id', $tenantId)->where('idx_subscriptions.status', '!=', 'cancelled')
                ->get(['idx_boards.state', 'idx_boards.other_states']);
        } catch (\Throwable) {
            return [];
        }
        $states = [];
        foreach ($boards as $b) {
            $states[] = strtoupper((string) $b->state);
            foreach ((array) json_decode((string) ($b->other_states ?? '[]'), true) as $s) {
                $states[] = strtoupper((string) $s);
            }
        }

        return array_values(array_unique(array_filter($states, fn (string $s): bool => isset(SampleHomes::MARKETS[$s]))));
    }
}
