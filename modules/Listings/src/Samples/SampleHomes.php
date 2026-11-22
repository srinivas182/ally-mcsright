<?php

declare(strict_types=1);

namespace Modules\Listings\Samples;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Listings\Events\ListingsChanged;
use Modules\Listings\Jobs\FetchListingPhotos;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Modules\Listings\Sync\ResoMapper;
use RuntimeException;

/**
 * TP1: sample homes. Every website shows homes from the moment it is made, so a brokerage can see and judge its
 * design before its MLS board is approved. One sample source per launch state, each with homes in that state's main
 * cities, real-looking facts and six licensed photos (Pexels, chosen by hand: no people, no brands).
 *
 * They go through the same path as an MLS feed (RESO record → listing → photos downloaded and stored → search), so
 * search, maps, property pages and compare all work. They are marked as samples, never indexed by search engines,
 * and replaced by the brokerage's own MLS listings the moment its first board goes live (DemoListings). The sources
 * have no credentials, so the MLS sync never runs on them; their photos are tagged here, so no AI is spent on them.
 */
final class SampleHomes
{
    public const SOURCE_PREFIX = 'sample-';

    public const OFFICE = 'RightAlly sample homes';

    public const DISCLAIMER = 'These homes are samples that show how this website works. They are not for sale and the addresses are made up. '
        .'Real listings from your MLS replace them when your board is approved.';

    /**
     * The cities each launch state's samples are in: name, county, latitude, longitude, ZIP prefix, typical price
     * range, and whether it is on the coast.
     *
     * @var array<string, list<array{0: string, 1: string, 2: float, 3: float, 4: string, 5: array{0: int, 1: int}, 6: bool}>>
     */
    public const MARKETS = [
        'TX' => [
            ['Houston', 'Harris', 29.7604, -95.3698, '770', [289_000, 2_450_000], false],
            ['Austin', 'Travis', 30.2672, -97.7431, '787', [399_000, 2_950_000], false],
            ['Dallas', 'Dallas', 32.7767, -96.7970, '752', [329_000, 2_650_000], false],
            ['San Antonio', 'Bexar', 29.4241, -98.4936, '782', [259_000, 1_450_000], false],
        ],
        'FL' => [
            ['Miami', 'Miami-Dade', 25.7617, -80.1918, '331', [449_000, 3_950_000], true],
            ['Tampa', 'Hillsborough', 27.9506, -82.4572, '336', [329_000, 2_250_000], true],
            ['Orlando', 'Orange', 28.5383, -81.3792, '328', [309_000, 1_850_000], false],
            ['Jacksonville', 'Duval', 30.3322, -81.6557, '322', [279_000, 1_650_000], true],
        ],
        'LA' => [
            ['New Orleans', 'Orleans', 29.9511, -90.0715, '701', [239_000, 1_750_000], false],
            ['Baton Rouge', 'East Baton Rouge', 30.4515, -91.1871, '708', [219_000, 1_150_000], false],
        ],
        'AL' => [
            ['Birmingham', 'Jefferson', 33.5186, -86.8104, '352', [229_000, 1_350_000], false],
            ['Huntsville', 'Madison', 34.7304, -86.5861, '358', [259_000, 1_250_000], false],
        ],
        'MS' => [
            ['Jackson', 'Hinds', 32.2988, -90.1848, '392', [189_000, 950_000], false],
            ['Gulfport', 'Harrison', 30.3674, -89.0928, '395', [219_000, 1_150_000], true],
        ],
    ];

    /** Homes per city. */
    public const PER_CITY = 6;

    private const STREETS = ['Magnolia Bend', 'Live Oak', 'Cypress Point', 'Willow Creek', 'Bluebonnet', 'Pecan Grove', 'Harbor View', 'Sandpiper', 'Heron Bay',
        'Laurel Ridge', 'Juniper', 'Stonebridge', 'Briar Hollow', 'Cedar Glen', 'Whispering Pines', 'Palmetto', 'Mockingbird', 'Saddle Creek', 'Riverstone', 'Coral Reef',
        'Bayou Vista', 'Lantana', 'Azalea', 'Sycamore Park', 'Wisteria', 'Old Mill', 'Oak Hollow', 'Seabreeze', 'Camellia', 'Silver Maple'];

    private const SUFFIXES = ['Drive', 'Lane', 'Court', 'Way', 'Trail', 'Boulevard', 'Circle', 'Place'];

    private const SUBDIVISIONS = ['The Reserve', 'Oak Park', 'Lakeside Estates', 'Heritage Hills', 'Harbor Pointe', 'Willowbrook', 'Riverbend', 'Cypress Landing',
        'Magnolia Park', 'The Heights', 'Westlake', 'Bay Colony', 'Pine Forest', 'Old Town', 'Stone Creek', 'Palm Harbor'];

    public function __construct(private readonly Photos $photos) {}

    public static function enabled(): bool
    {
        return (bool) config('listings.sample_homes', true);
    }

    /**
     * Creates or refreshes every state's sample source and homes. Safe to run again: unchanged homes are left as
     * they are; photos already stored are not downloaded again.
     *
     * @return array{sources: int, homes: int, photos: int}
     */
    public function install(): array
    {
        $profile = RulesProfile::query()->firstOrCreate(['name' => 'Sample homes'], ['program' => 'idx', 'attribution_template' => 'Sample home: not for sale',
            'disclaimer' => self::DISCLAIMER, 'sold_allowed' => false, 'sold_delay_days' => 0, 'freshness_minutes' => 43_200, 'index_off_market' => false]);
        $sources = 0;
        $homes = 0;
        $queued = 0;
        foreach (array_keys(self::MARKETS) as $state) {
            $source = MlsSource::query()->updateOrCreate(['key' => self::SOURCE_PREFIX.strtolower($state)], [
                'name' => 'Sample homes ('.$state.')', 'states' => [$state], 'provider' => 'sample', 'base_url' => 'https://samples.rightally.invalid',
                'status' => 'active', 'is_demo' => true, 'rules_profile_id' => $profile->id, 'full_sync_done' => false, 'last_success_at' => CarbonImmutable::now(),
            ]);
            $sources++;
            [$count, $photos] = $this->installState($source, $state);
            $homes += $count;
            $queued += $photos;
        }

        return ['sources' => $sources, 'homes' => $homes, 'photos' => $queued];
    }

    /**
     * The sample sources, one per state (or the one for a state).
     *
     * @return list<int>
     */
    public static function sourceIds(?string $state = null): array
    {
        $q = MlsSource::query()->where('key', 'like', self::SOURCE_PREFIX.'%')->where('is_demo', true)->where('status', 'active');

        return array_values($q->get(['id', 'states'])->filter(fn (MlsSource $s): bool => $state === null || in_array(strtoupper($state), (array) $s->states, true))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * @return array{0: int, 1: int} homes written, photos queued
     */
    private function installState(MlsSource $source, string $state): array
    {
        $records = self::records($state);
        $keys = [];
        $changed = [];
        $needPhotos = [];
        $db = DB::connection(Connections::listings());
        $db->transaction(function () use ($records, $source, &$keys, &$changed, &$needPhotos, $db): void {
            foreach ($records as $record) {
                $row = ResoMapper::map($record, $source->key);
                $keys[] = (string) $row['listing_key'];
                $listing = Listing::query()->where('mls_source_id', $source->id)->where('listing_key', $row['listing_key'])->first()
                    ?? new Listing(['mls_source_id' => $source->id, 'ulid' => (string) Str::ulid()]);
                $listing->forceFill($row + ['mls_source_id' => $source->id])->save();
                if ($this->photos->syncList($listing, $record)) {
                    $needPhotos[] = $listing->id;
                }
                // The photos are labelled here, so the AI photo tagging and the nearby-places lookups skip samples.
                foreach ($record['Media'] as $m) {
                    ListingMedia::query()->where('listing_id', $listing->id)->where('media_key', $m['MediaKey'])
                        ->update(['room' => $m['Room'], 'alt_text' => $m['ShortDescription'], 'tags' => json_encode($m['Tags']), 'tagged_at' => now()]);
                }
                $listing->forceFill(['photos_tagged_at' => now(), 'places_at' => now()])->saveQuietly();
                $db->table('listing_payloads')->updateOrInsert(['listing_id' => $listing->id],
                    ['payload' => json_encode($record, JSON_UNESCAPED_SLASHES), 'received_at' => now()]);
                $changed[] = $listing->id;
            }
            // Homes no longer in the list (fewer cities, say) go.
            Listing::query()->where('mls_source_id', $source->id)->whereNotIn('listing_key', $keys)->get()->each(fn (Listing $l) => $l->delete());
        });
        foreach ($needPhotos as $id) {
            FetchListingPhotos::dispatch($id);
        }
        if ($changed !== []) {
            ListingsChanged::dispatch($source->id, $changed, 'changed');
        }

        return [count($changed), count($needPhotos)];
    }

    /**
     * The state's sample homes as RESO records, the same every time (the numbers come from a fixed seed).
     *
     * @return list<array<string, mixed>>
     */
    public static function records(string $state): array
    {
        $state = strtoupper($state);
        $markets = self::MARKETS[$state] ?? throw new RuntimeException("No sample homes for {$state}.");
        $photos = self::photoGroups();
        $out = [];
        // Each kind of photo is taken in turn (starting at a different place in each state), so neighbouring homes look different.
        $turn = [];
        $next = function (string $group) use (&$turn, $photos, $state): array {
            $turn[$group] = ($turn[$group] ?? (crc32($state.$group) % max(1, count($photos[$group] ?? [])))) + 1;

            return self::pick($photos, $group, $turn[$group] - 1);
        };
        foreach ($markets as $c => [$city, $county, $lat, $lng, $zip, [$low, $high], $coast]) {
            mt_srand(crc32($state.$city));
            for ($i = 0; $i < self::PER_CITY; $i++) {
                $n = $c * self::PER_CITY + $i;
                // A spread of homes: two luxury, two family, one townhome or coastal, one condo.
                $kind = ['luxury', 'family', 'luxury', 'family', $coast ? 'coastal' : 'townhome', 'condo'][$i];
                $price = match ($kind) {
                    'luxury' => (int) round(mt_rand((int) ($high * 0.45), $high) / 5000) * 5000,
                    'condo' => (int) round(mt_rand($low, (int) ($low + ($high - $low) * 0.25)) / 1000) * 1000,
                    default => (int) round(mt_rand($low, (int) ($low + ($high - $low) * 0.4)) / 1000) * 1000,
                };
                $beds = match ($kind) {
                    'luxury' => mt_rand(4, 6), 'condo' => mt_rand(1, 3), 'townhome' => mt_rand(2, 4), default => mt_rand(3, 5),
                };
                $sqft = match ($kind) {
                    'luxury' => mt_rand(3600, 7800), 'condo' => mt_rand(780, 1900), 'townhome' => mt_rand(1500, 2600), default => mt_rand(1900, 3600),
                };
                $full = max(1, min($beds, match ($kind) {
                    'luxury' => $beds - mt_rand(0, 1), 'condo' => min(2, $beds), default => $beds - 1 - mt_rand(0, 1)
                }));
                $half = $kind === 'condo' ? 0 : mt_rand(0, 1);
                $year = $kind === 'luxury' ? mt_rand(2008, 2025) : mt_rand(1978, 2024);
                $pool = $kind === 'luxury' || ($kind === 'coastal' && mt_rand(0, 1) === 1);
                $water = $kind === 'coastal' || ($coast && $kind === 'luxury' && mt_rand(0, 2) === 0);
                $garage = $kind === 'condo' ? 1 : ($kind === 'luxury' ? 3 : 2);
                $days = mt_rand(1, 58);
                $street = self::STREETS[($n * 7 + $c) % count(self::STREETS)].' '.self::SUFFIXES[($n + $i) % count(self::SUFFIXES)];
                $sub = self::SUBDIVISIONS[($n * 3 + $i) % count(self::SUBDIVISIONS)];
                $exterior = $next(match ($kind) {
                    'luxury' => 'exterior_luxury', 'condo' => 'exterior_condo', 'coastal' => 'exterior_coastal',
                    'townhome' => $c === 0 ? 'exterior_townhome' : 'exterior_family', default => 'exterior_family',
                });
                $rooms = [
                    ['front', $exterior, 'Front of the home'],
                    ['living_room', $next('living'), 'Living room'],
                    ['kitchen', $next('kitchen'), 'Kitchen'],
                    ['bedroom', $next('bedroom'), 'Primary bedroom'],
                    ['bathroom', $next('bathroom'), 'Primary bathroom'],
                    $pool ? ['backyard', $next('backyard'), 'Backyard and pool'] : ['dining_room', $next('dining'), 'Dining room'],
                ];
                $media = [];
                foreach ($rooms as $p => [$room, $photo, $caption]) {
                    $tags = match ($room) {
                        'kitchen' => ['kitchen_island', 'stone_countertops', 'stainless_appliances'], 'living_room' => ['hardwood_floors', 'high_ceilings'],
                        'backyard' => ['pool', 'deck_or_patio'], 'bedroom' => ['walk_in_closet'], 'front' => ['garage'], default => [],
                    };
                    $media[] = ['MediaKey' => 'p'.$photo['id'], 'MediaURL' => $photo['image'], 'Order' => $p, 'MediaCategory' => 'Photo',
                        'ShortDescription' => $caption.', '.$city, 'Room' => $room, 'Tags' => $tags];
                }
                $type = $kind === 'condo' ? 'Condominium' : ($kind === 'townhome' ? 'Townhouse' : 'Single Family Residence');
                $listed = CarbonImmutable::parse('2026-11-01')->subDays($days);
                $out[] = [
                    'ListingKey' => 'S'.$state.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT), 'ListingId' => 'SAMPLE-'.$state.'-'.($n + 1),
                    'StandardStatus' => 'Active', 'MlsStatus' => 'Active', 'PropertyType' => 'Residential', 'PropertySubType' => $type,
                    'ListPrice' => $price, 'OriginalListPrice' => $i === 1 ? (int) round($price * 1.04 / 1000) * 1000 : $price,
                    'BedroomsTotal' => $beds, 'BathroomsFull' => $full, 'BathroomsHalf' => $half, 'LivingArea' => $sqft,
                    'LotSizeSquareFeet' => $kind === 'condo' ? null : ($kind === 'luxury' ? mt_rand(12_000, 43_560) : mt_rand(5_200, 11_000)), 'YearBuilt' => $year,
                    'StreetNumber' => (string) mt_rand(100, 9899), 'StreetName' => $street, 'UnitNumber' => $kind === 'condo' ? (string) mt_rand(2, 24).'0'.mt_rand(1, 8) : null,
                    'City' => $city, 'StateOrProvince' => $state, 'PostalCode' => $zip.str_pad((string) mt_rand(1, 99), 2, '0', STR_PAD_LEFT), 'CountyOrParish' => $county,
                    'SubdivisionName' => $sub,
                    // Within about 10 miles of the city centre.
                    'Latitude' => round($lat + (mt_rand(-1400, 1400) / 10000), 6), 'Longitude' => round($lng + (mt_rand(-1400, 1400) / 10000), 6),
                    'PublicRemarks' => self::remarks($kind, $beds, $sqft, $city, $sub, $pool, $water, $year),
                    'ListOfficeName' => self::OFFICE, 'ListOfficeMlsId' => 'SAMPLE', 'ListAgentFullName' => 'Sample listing', 'ListAgentMlsId' => 'SAMPLE',
                    'PhotosCount' => count($media), 'DaysOnMarket' => $days,
                    'OnMarketDate' => $listed->toDateString(), 'ListingContractDate' => $listed->toDateString(), 'ModificationTimestamp' => '2026-11-01T00:00:00Z',
                    'PoolPrivateYN' => $pool, 'WaterfrontYN' => $water, 'NewConstructionYN' => $year >= 2025, 'GarageSpaces' => $garage,
                    'InternetEntireListingDisplayYN' => true, 'InternetAddressDisplayYN' => true, 'InternetAutomatedValuationDisplayYN' => false, 'InternetConsumerCommentYN' => false,
                    'Media' => $media,
                ];
            }
        }

        return $out;
    }

    /**
     * The chosen photos by kind (exterior_luxury, living, kitchen, city_houston …), each with its image and page.
     *
     * @return array<string, list<array{id: int, image: string, page: string}>>
     */
    public static function photoGroups(): array
    {
        static $groups = null;
        if ($groups === null) {
            $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/samples/photos.json'), true);
            $groups = is_array($data) && is_array($data['groups'] ?? null) ? $data['groups'] : [];
        }

        return $groups;
    }

    /**
     * @param  array<string, list<array{id: int, image: string, page: string}>>  $photos
     * @return array{id: int, image: string, page: string}
     */
    private static function pick(array $photos, string $group, int $n): array
    {
        $list = $photos[$group] ?? [];
        if ($list === []) {
            throw new RuntimeException("No sample photos for {$group}.");
        }

        return $list[$n % count($list)];
    }

    private static function remarks(string $kind, int $beds, int $sqft, string $city, string $sub, bool $pool, bool $water, int $year): string
    {
        $size = number_format($sqft);
        $open = match ($kind) {
            'luxury' => "A striking {$beds}-bedroom home in {$sub} with {$size} square feet of light-filled living space.",
            'condo' => "A bright {$beds}-bedroom condo close to the best of {$city}, with {$size} square feet and an easy, lock-and-leave lifestyle.",
            'townhome' => "A low-maintenance {$beds}-bedroom townhome in {$sub}, with {$size} square feet over two floors.",
            'coastal' => "Coastal living in this {$beds}-bedroom home with {$size} square feet, wide porches and room for guests.",
            default => "A welcoming {$beds}-bedroom family home in {$sub} with {$size} square feet and a flexible floor plan.",
        };
        $middle = 'The open kitchen has a large island, stone counters and stainless appliances, and flows into the living and dining areas.';
        $extra = trim(($pool ? ' The backyard has a private pool and covered patio for year-round entertaining.' : '')
            .($water ? ' Enjoy water views and breezes from the main living spaces.' : '')
            .($year >= 2020 ? ' Built in '.$year.', with modern energy-efficient systems.' : ''));

        return trim($open.' '.$middle.' '.$extra.' Close to schools, parks, shopping and dining in '.$city.'.');
    }
}
