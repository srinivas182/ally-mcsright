<?php

declare(strict_types=1);

namespace Modules\Listings\Places;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Listings\Models\Listing;
use Modules\Listings\Search\ListingSearch;
use Throwable;

/**
 * Nearby schools, transit, groceries and coffee (C03, specs/websites/02) through the provider HQ chose in Outside services → Nearby
 * schools and transit: OpenStreetMap through an Overpass server (recommended, free), Geoapify Places, or Google
 * Places. Each for-sale listing whose address may be shown is looked up once, and again every 30 days; the nearest
 * places are kept with straight-line distances, so listing pages never wait for a provider.
 *
 * Fair housing: schools are named with their distance only, never rated or ranked, and pages say that attendance
 * depends on the school district. Test mode looks up a few listings a day for HQ to check; nothing reaches listing
 * pages until Live.
 */
final class NearbyPlaces
{
    /** Listings looked up per run. Defaults to review. */
    public const DAILY_LIVE = 1000;

    public const DAILY_TEST = 20;

    /** Days before a listing is looked up again (also Google's limit on keeping results). */
    public const REFRESH_DAYS = 30;

    /** How far to look (meters) and how many to keep, per kind. */
    private const LIMITS = ['school' => [3219, 5], 'bus' => [1609, 1], 'rail' => [4828, 2], 'grocery' => [3219, 2], 'coffee' => [1609, 2]];

    /** Which list each group belongs to on the page. */
    private const KINDS = ['school' => 'school', 'bus' => 'transit', 'rail' => 'transit', 'grocery' => 'shop', 'coffee' => 'shop'];

    public const SUBTYPES = ['elementary' => 'Elementary school', 'middle' => 'Middle school', 'high' => 'High school', 'school' => 'School',
        'bus' => 'Bus stop', 'train' => 'Train station', 'subway' => 'Subway station', 'light_rail' => 'Light rail or tram stop', 'grocery' => 'Grocery store', 'coffee' => 'Coffee shop'];

    public const CREDITS = ['osm' => '© OpenStreetMap contributors', 'geoapify' => 'Powered by Geoapify · © OpenStreetMap contributors', 'google_places' => 'Places data © Google'];

    /**
     * Looks up waiting listings, newest first, within today's allowance.
     *
     * @return array{listings: int, places: int, mode: string|null}
     */
    public function run(?int $limit = null): array
    {
        $service = app(OutsideServices::class)->active('nearby_places');
        if ($service === null) {
            return ['listings' => 0, 'places' => 0, 'mode' => null];
        }
        $live = $service['mode'] === 'live';
        $query = Listing::query()->whereNull('off_market_at')->whereIn('standard_status', ['Active', 'Coming Soon'])->where('address_display_allowed', true)
            ->whereNotNull('latitude')->whereNotNull('longitude')->orderByDesc('on_market_date')->orderByDesc('id');
        $live
            ? $query->where(fn ($w) => $w->whereNull('places_at')->orWhere('places_at', '<', CarbonImmutable::now()->subDays(self::REFRESH_DAYS)))
            : $query->whereNotExists(fn ($q) => $q->from('listing_places')->whereColumn('listing_places.listing_id', 'listings.id'));
        $listings = 0;
        $places = 0;
        foreach ($query->limit($limit ?? ($live ? self::DAILY_LIVE : self::DAILY_TEST))->get() as $listing) {
            $found = $this->lookUp($service, (float) $listing->latitude, (float) $listing->longitude);
            if ($found === null) {
                continue; // tried again next run
            }
            $this->keep($listing, $found, $service['provider'], $live);
            $listings++;
            $places += count($found);
        }

        return ['listings' => $listings, 'places' => $places, 'mode' => $service['mode']];
    }

    /**
     * The nearest places for one point, already chosen and sorted (null: the provider could not answer).
     *
     * @param  array{provider: string, mode: string, keys: array<string, string>}  $service
     * @return list<array{kind: string, subtype: string, name: string, meters: int, latitude: float, longitude: float}>|null
     */
    public function lookUp(array $service, float $lat, float $lng): ?array
    {
        $k = $service['keys'];
        try {
            $raw = match ($service['provider']) {
                'osm' => $this->overpass((string) ($k['host'] ?? ''), $lat, $lng),
                'geoapify' => $this->geoapify((string) ($k['api_key'] ?? ''), $lat, $lng),
                'google_places' => $this->google((string) ($k['api_key'] ?? ''), $lat, $lng),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $raw === null ? null : self::choose($raw, $lat, $lng);
    }

    /**
     * Keeps the nearest of each kind: measured, de-duplicated by name (a stop on each side of the road is one stop).
     *
     * @param  list<array{group: string, subtype: string, name: string, latitude: float, longitude: float}>  $raw
     * @return list<array{kind: string, subtype: string, name: string, meters: int, latitude: float, longitude: float}>
     */
    public static function choose(array $raw, float $lat, float $lng): array
    {
        $byGroup = [];
        foreach ($raw as $p) {
            $name = trim(preg_replace('/\s+/', ' ', $p['name']) ?? '');
            if ($name === '' || ! isset(self::LIMITS[$p['group']])) {
                continue;
            }
            $meters = (int) round(ListingSearch::miles($lat, $lng, $p['latitude'], $p['longitude']) * 1609.344);
            if ($meters > self::LIMITS[$p['group']][0]) {
                continue;
            }
            $key = mb_strtolower($name);
            if (! isset($byGroup[$p['group']][$key]) || $byGroup[$p['group']][$key]['meters'] > $meters) {
                $byGroup[$p['group']][$key] = ['kind' => self::KINDS[$p['group']], 'subtype' => $p['subtype'], 'name' => mb_substr($name, 0, 160),
                    'meters' => $meters, 'latitude' => $p['latitude'], 'longitude' => $p['longitude']];
            }
        }
        $out = [];
        foreach (self::LIMITS as $group => [, $keep]) {
            $list = array_values($byGroup[$group] ?? []);
            usort($list, fn (array $a, array $b): int => $a['meters'] <=> $b['meters']);
            array_push($out, ...array_slice($list, 0, $keep));
        }

        return $out;
    }

    /**
     * A listing's places for its pages, or null until looked up in Live.
     *
     * @return array{schools: list<array{name: string, type: string, miles: string}>, transit: list<array{name: string, type: string, miles: string}>, shops: list<array{name: string, type: string, miles: string}>, credit: string, summary: string}|null
     */
    public static function forListing(Listing $listing): ?array
    {
        if ($listing->places_at === null || ! $listing->address_display_allowed) {
            return null;
        }
        $rows = DB::connection(Connections::listings())->table('listing_places')->where('listing_id', $listing->id)->orderBy('meters')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $show = fn ($r): array => ['name' => (string) $r->name, 'type' => self::SUBTYPES[(string) $r->subtype] ?? 'Place', 'miles' => self::miles((int) $r->meters)];
        $schools = array_values($rows->where('kind', 'school')->map($show)->all());
        $transit = array_values($rows->where('kind', 'transit')->map($show)->all());
        $shops = array_values($rows->where('kind', 'shop')->map($show)->all());
        $parts = [];
        if ($schools !== []) {
            $parts[] = 'The nearest school is '.$schools[0]['name'].', '.$schools[0]['miles'].' away.';
        }
        if ($transit !== []) {
            $parts[] = 'The nearest '.mb_strtolower($transit[0]['type']).' is '.$transit[0]['name'].', '.$transit[0]['miles'].' away.';
        }

        return ['schools' => $schools, 'transit' => $transit, 'shops' => $shops, 'credit' => self::CREDITS[(string) $rows->first()->provider] ?? '', 'summary' => implode(' ', $parts)];
    }

    /** "0.4 miles", "1 mile", "less than 0.1 miles" (straight line). */
    public static function miles(int $meters): string
    {
        $miles = round($meters / 1609.344, 1);
        if ($miles < 0.1) {
            return 'less than 0.1 miles';
        }

        return $miles == 1.0 ? '1 mile' : rtrim(rtrim(number_format($miles, 1), '0'), '.').' miles';
    }

    /** Elementary, middle or high from a school's name (or what the provider said), else "school". */
    public static function schoolLevel(string $name, ?string $hint = null): string
    {
        $n = ' '.mb_strtolower($name.' '.($hint ?? '')).' ';
        foreach (['elementary' => [' elementary', ' primary'], 'middle' => [' middle', ' junior high', ' intermediate'], 'high' => [' high school', ' high ', ' secondary']] as $level => $words) {
            foreach ($words as $w) {
                if (str_contains($n, $w)) {
                    return $level;
                }
            }
        }

        return 'school';
    }

    /**
     * @param  list<array{kind: string, subtype: string, name: string, meters: int, latitude: float, longitude: float}>  $found
     */
    private function keep(Listing $listing, array $found, string $provider, bool $live): void
    {
        $db = DB::connection(Connections::listings());
        $db->transaction(function () use ($db, $listing, $found, $provider): void {
            $db->table('listing_places')->where('listing_id', $listing->id)->delete();
            $db->table('listing_places')->insert(array_map(fn (array $p): array => [...$p, 'listing_id' => $listing->id, 'provider' => $provider], $found));
        });
        if ($live) {
            $listing->forceFill(['places_at' => CarbonImmutable::now()])->saveQuietly();
        }
    }

    /** @return list<array{group: string, subtype: string, name: string, latitude: float, longitude: float}>|null */
    private function overpass(string $host, float $lat, float $lng): ?array
    {
        if (! str_starts_with($host, 'https://')) {
            return null;
        }
        $at = sprintf('%.6F,%.6F', $lat, $lng);
        [$school, $bus, $rail, $grocery, $coffee] = [self::LIMITS['school'][0], self::LIMITS['bus'][0], self::LIMITS['rail'][0], self::LIMITS['grocery'][0], self::LIMITS['coffee'][0]];
        $q = "[out:json][timeout:25];(nwr[\"amenity\"=\"school\"](around:{$school},{$at});node[\"highway\"=\"bus_stop\"](around:{$bus},{$at});"
            ."nwr[\"railway\"~\"^(station|halt|tram_stop)$\"](around:{$rail},{$at});nwr[\"public_transport\"=\"station\"][\"train\"=\"yes\"](around:{$rail},{$at});"
            ."nwr[\"shop\"=\"supermarket\"](around:{$grocery},{$at});nwr[\"amenity\"=\"cafe\"](around:{$coffee},{$at}););out center tags;";
        $r = Http::timeout(40)->acceptJson()->asForm()->post($host, ['data' => $q]);
        $elements = $r->json('elements');
        if ($r->failed() || ! is_array($elements)) {
            return null;
        }
        $out = [];
        foreach ($elements as $e) {
            $t = is_array($e) ? (array) ($e['tags'] ?? []) : [];
            $plat = $e['lat'] ?? $e['center']['lat'] ?? null;
            $plng = $e['lon'] ?? $e['center']['lon'] ?? null;
            if (! is_string($t['name'] ?? null) || ! is_numeric($plat) || ! is_numeric($plng)) {
                continue;
            }
            if (($t['amenity'] ?? null) === 'school') {
                $out[] = ['group' => 'school', 'subtype' => self::schoolLevel($t['name'], is_string($t['isced:level'] ?? null) ? self::isced($t['isced:level']) : null), 'name' => $t['name'],
                    'latitude' => (float) $plat, 'longitude' => (float) $plng];
            } elseif (($t['shop'] ?? null) === 'supermarket') {
                $out[] = ['group' => 'grocery', 'subtype' => 'grocery', 'name' => $t['name'], 'latitude' => (float) $plat, 'longitude' => (float) $plng];
            } elseif (($t['amenity'] ?? null) === 'cafe') {
                $out[] = ['group' => 'coffee', 'subtype' => 'coffee', 'name' => $t['name'], 'latitude' => (float) $plat, 'longitude' => (float) $plng];
            } elseif (($t['highway'] ?? null) === 'bus_stop') {
                $out[] = ['group' => 'bus', 'subtype' => 'bus', 'name' => $t['name'], 'latitude' => (float) $plat, 'longitude' => (float) $plng];
            } else {
                $sub = ($t['station'] ?? null) === 'subway' || ($t['subway'] ?? null) === 'yes' ? 'subway'
                    : (in_array($t['station'] ?? null, ['light_rail', 'tram'], true) || ($t['railway'] ?? null) === 'tram_stop' ? 'light_rail' : 'train');
                $out[] = ['group' => 'rail', 'subtype' => $sub, 'name' => $t['name'], 'latitude' => (float) $plat, 'longitude' => (float) $plng];
            }
        }

        return $out;
    }

    /** OpenStreetMap's school level numbers (1 = primary, 2 = lower secondary, 3 = upper secondary). */
    private static function isced(string $level): ?string
    {
        return match (trim(explode(';', $level)[0])) {
            '1' => 'elementary', '2' => 'middle', '3' => 'high school', default => null,
        };
    }

    /** @return list<array{group: string, subtype: string, name: string, latitude: float, longitude: float}>|null */
    private function geoapify(string $key, float $lat, float $lng): ?array
    {
        $r = Http::timeout(30)->acceptJson()->get('https://api.geoapify.com/v2/places', [
            'categories' => 'education.school,public_transport.bus,public_transport.train,public_transport.subway,public_transport.light_rail,public_transport.tram,commercial.supermarket,catering.cafe',
            'filter' => sprintf('circle:%.6F,%.6F,%d', $lng, $lat, self::LIMITS['rail'][0]), 'bias' => sprintf('proximity:%.6F,%.6F', $lng, $lat), 'limit' => 80, 'apiKey' => $key]);
        $features = $r->json('features');
        if ($r->failed() || ! is_array($features)) {
            return null;
        }
        $out = [];
        foreach ($features as $f) {
            $p = is_array($f) ? (array) ($f['properties'] ?? []) : [];
            $cats = array_map('strval', (array) ($p['categories'] ?? []));
            if (! is_string($p['name'] ?? null) || ! is_numeric($p['lat'] ?? null) || ! is_numeric($p['lon'] ?? null)) {
                continue;
            }
            [$group, $sub] = match (true) {
                in_array('education.school', $cats, true) => ['school', self::schoolLevel($p['name'])],
                in_array('public_transport.subway', $cats, true) => ['rail', 'subway'],
                in_array('public_transport.light_rail', $cats, true), in_array('public_transport.tram', $cats, true) => ['rail', 'light_rail'],
                in_array('public_transport.train', $cats, true) => ['rail', 'train'],
                in_array('public_transport.bus', $cats, true) => ['bus', 'bus'],
                in_array('commercial.supermarket', $cats, true) => ['grocery', 'grocery'],
                in_array('catering.cafe', $cats, true) => ['coffee', 'coffee'],
                default => [null, null],
            };
            if ($group !== null) {
                $out[] = ['group' => $group, 'subtype' => (string) $sub, 'name' => $p['name'], 'latitude' => (float) $p['lat'], 'longitude' => (float) $p['lon']];
            }
        }

        return $out;
    }

    /** @return list<array{group: string, subtype: string, name: string, latitude: float, longitude: float}>|null */
    private function google(string $key, float $lat, float $lng): ?array
    {
        $ask = fn (array $types, int $radius) => Http::timeout(30)->acceptJson()
            ->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'places.displayName,places.location,places.types'])
            ->post('https://places.googleapis.com/v1/places:searchNearby', ['includedTypes' => $types, 'maxResultCount' => 20, 'rankPreference' => 'DISTANCE',
                'locationRestriction' => ['circle' => ['center' => ['latitude' => $lat, 'longitude' => $lng], 'radius' => (float) $radius]]]);
        $schools = $ask(['primary_school', 'secondary_school', 'school'], self::LIMITS['school'][0]);
        $transit = $ask(['bus_stop', 'train_station', 'subway_station', 'light_rail_station'], self::LIMITS['rail'][0]);
        $shops = $ask(['supermarket', 'grocery_store', 'cafe', 'coffee_shop'], self::LIMITS['grocery'][0]);
        if ($schools->failed() || $transit->failed() || $shops->failed()) {
            return null;
        }
        $out = [];
        foreach ([...(array) $schools->json('places', []), ...(array) $transit->json('places', []), ...(array) $shops->json('places', [])] as $p) {
            $name = is_array($p) ? ($p['displayName']['text'] ?? null) : null;
            $types = is_array($p) ? array_map('strval', (array) ($p['types'] ?? [])) : [];
            if (! is_string($name) || ! is_numeric($p['location']['latitude'] ?? null) || ! is_numeric($p['location']['longitude'] ?? null)) {
                continue;
            }
            [$group, $sub] = match (true) {
                array_intersect(['primary_school', 'secondary_school', 'school'], $types) !== [] => ['school', self::schoolLevel($name, in_array('primary_school', $types, true) ? 'elementary' : null)],
                in_array('subway_station', $types, true) => ['rail', 'subway'],
                in_array('light_rail_station', $types, true) => ['rail', 'light_rail'],
                in_array('train_station', $types, true) => ['rail', 'train'],
                in_array('bus_stop', $types, true) => ['bus', 'bus'],
                array_intersect(['supermarket', 'grocery_store'], $types) !== [] => ['grocery', 'grocery'],
                array_intersect(['cafe', 'coffee_shop'], $types) !== [] => ['coffee', 'coffee'],
                default => [null, null],
            };
            if ($group !== null) {
                $out[] = ['group' => $group, 'subtype' => (string) $sub, 'name' => $name, 'latitude' => (float) $p['location']['latitude'], 'longitude' => (float) $p['location']['longitude']];
            }
        }

        return $out;
    }
}
