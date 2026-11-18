<?php

declare(strict_types=1);

namespace Modules\Listings\Media;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Throwable;

/**
 * Photo tagging (C03, specs/websites/02) through the provider HQ chose in Outside services → Photo tagging: Restb.ai
 * (recommended, made for real estate), Azure AI Vision or Google Cloud Vision. Each stored photo of a for-sale
 * listing gets a room, the home features seen in it and plain-words alt text ("Kitchen with a kitchen island").
 *
 * Only the home is described: the vocabulary below has rooms and features, and anything else a provider returns
 * (people, pets, cars, brands) is dropped. An MLS caption always wins over our alt text.
 *
 * Test mode tags a few photos a day for HQ to check (Outside services → Photo tagging results); nothing reaches
 * public pages or search until Live. Switching to Live reuses photos already tagged, so they are not paid for twice.
 */
final class PhotoTags
{
    /** Photos tagged per run (Live), and in Test. Defaults to review: each photo is paid for. */
    public const DAILY_LIVE = 2000;

    public const DAILY_TEST = 50;

    /** Photos per listing: the first ones, in MLS order. */
    public const PER_LISTING = 25;

    /** Confidence below which a provider's answer is ignored. */
    private const MIN_CONFIDENCE = 0.6;

    /** Rooms we name, and the words that point to them in providers' labels (first match wins). */
    public const ROOMS = [
        'kitchen' => ['Kitchen', ['kitchen']],
        'living_room' => ['Living room', ['living room', 'living_room', 'family room', 'great room', 'den']],
        'dining_room' => ['Dining room', ['dining']],
        'bedroom' => ['Bedroom', ['bedroom', 'bed room', 'nursery']],
        'bathroom' => ['Bathroom', ['bathroom', 'bath room', 'restroom', 'shower room']],
        'office' => ['Home office', ['office', 'study']],
        'laundry' => ['Laundry room', ['laundry']],
        'garage' => ['Garage', ['garage']],
        'front' => ['Front of the home', ['front', 'facade', 'exterior', 'house', 'cottage', 'villa']],
        'backyard' => ['Backyard', ['backyard', 'back yard', 'yard', 'garden', 'patio', 'deck', 'lawn']],
        'view' => ['View', ['view', 'aerial', 'waterfront', 'beach', 'lake', 'ocean']],
    ];

    /** Home features, their plain label, and the words that point to them (any match). */
    public const FEATURES = [
        'pool' => ['Pool', ['swimming pool', 'swimming_pool', 'pool']],
        'hot_tub' => ['Hot tub', ['hot tub', 'hot_tub', 'jacuzzi', 'spa tub']],
        'fireplace' => ['Fireplace', ['fireplace', 'hearth']],
        'kitchen_island' => ['Kitchen island', ['kitchen island', 'kitchen_island']],
        'stainless_appliances' => ['Stainless appliances', ['stainless']],
        'stone_countertops' => ['Stone countertops', ['granite', 'quartz', 'marble countertop', 'marble_countertop']],
        'hardwood_floors' => ['Hardwood floors', ['hardwood', 'wood floor', 'wood_floor', 'wooden floor', 'parquet']],
        'high_ceilings' => ['High ceilings', ['vaulted', 'cathedral ceiling', 'high ceiling', 'beamed ceiling', 'exposed beam']],
        'walk_in_closet' => ['Walk-in closet', ['walk-in closet', 'walk_in_closet', 'walk in closet']],
        'deck_or_patio' => ['Deck or patio', ['deck', 'patio', 'porch', 'veranda', 'terrace']],
        'fenced_yard' => ['Fenced yard', ['fence', 'fenced']],
        'water_view' => ['Water view', ['waterfront', 'water view', 'ocean view', 'lake view', 'sea view', 'beach']],
        'solar_panels' => ['Solar panels', ['solar']],
        'garage' => ['Garage', ['garage']],
    ];

    /** Words that are never features even when they contain one ("pool table", "island" in a place name…). */
    private const NOT = ['pool table', 'billiard', 'car pool', 'carpool'];

    /**
     * Tags waiting photos of for-sale listings, newest listings first, within today's allowance. Returns counts.
     *
     * @return array{photos: int, listings: int, mode: string|null}
     */
    public function run(?int $limit = null): array
    {
        $service = app(OutsideServices::class)->active('photo_tags');
        if ($service === null) {
            return ['photos' => 0, 'listings' => 0, 'mode' => null];
        }
        $live = $service['mode'] === 'live';
        $allowance = $limit ?? ($live ? self::DAILY_LIVE : self::DAILY_TEST);
        $photos = 0;
        $listings = 0;
        $query = Listing::query()->whereNull('off_market_at')->whereIn('standard_status', ['Active', 'Coming Soon'])->where('photos_count', '>', 0)
            ->orderByDesc('on_market_date')->orderByDesc('id')
            // Wait until every photo is downloaded, so features are gathered from the whole set.
            ->whereNotExists(fn ($q) => $q->from('listing_media')->whereColumn('listing_media.listing_id', 'listings.id')->where('status', 'pending'));
        $live ? $query->whereNull('photos_tagged_at') : $query->whereExists(fn ($q) => $q->from('listing_media')->whereColumn('listing_media.listing_id', 'listings.id')
            ->where('status', 'stored')->whereNull('tagged_at'));
        foreach ($query->limit(500)->cursor() as $listing) {
            if ($photos >= $allowance) {
                break;
            }
            $photos += $this->tagListing($listing, $service, $allowance - $photos, $live);
            $listings++;
        }

        return ['photos' => $photos, 'listings' => $listings, 'mode' => $service['mode']];
    }

    /**
     * Tags one listing's untagged photos (up to the allowance); in Live, then gathers the listing's features.
     * Returns how many photos were sent to the provider.
     *
     * @param  array{provider: string, mode: string, keys: array<string, string>}  $service
     */
    public function tagListing(Listing $listing, array $service, int $allowance, bool $live): int
    {
        $sent = 0;
        $media = ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'stored')->orderBy('position')->limit(self::PER_LISTING)->get();
        foreach ($media as $m) {
            if ($m->getAttribute('tagged_at') !== null) {
                continue;
            }
            if ($sent >= $allowance) {
                return $sent; // the rest tomorrow; features are gathered once every photo is done
            }
            $sent++;
            $found = $this->ask($service, $this->imageUrl($m));
            if ($found === null) {
                continue; // tried again next run
            }
            $m->forceFill(['room' => $found['room'], 'tags' => json_encode($found['features']), 'alt_text' => self::altText($found['room'], $found['features']),
                'tagged_at' => CarbonImmutable::now()])->save();
        }
        if ($live && $media->every(fn (ListingMedia $m): bool => $m->getAttribute('tagged_at') !== null)) {
            $this->gather($listing);
        }

        return $sent;
    }

    /** Puts the listing's features (from all its tagged photos) into listing_features, for "Has" search filters. */
    public function gather(Listing $listing): void
    {
        $features = [];
        foreach (ListingMedia::query()->where('listing_id', $listing->id)->whereNotNull('tagged_at')->get(['room', 'tags']) as $row) {
            if ($row->getAttribute('room') === 'garage') {
                $features['garage'] = true;
            }
            foreach ((array) json_decode((string) $row->getAttribute('tags'), true) as $f) {
                if (is_string($f) && isset(self::FEATURES[$f])) {
                    $features[$f] = true;
                }
            }
        }
        $db = DB::connection(Connections::listings());
        $db->transaction(function () use ($db, $listing, $features): void {
            $db->table('listing_features')->where('listing_id', $listing->id)->delete();
            $db->table('listing_features')->insert(array_map(fn (string $f): array => ['listing_id' => $listing->id, 'feature' => $f], array_keys($features)));
        });
        $listing->forceFill(['photos_tagged_at' => CarbonImmutable::now()])->saveQuietly();
    }

    /**
     * The listing's features as plain labels, for listing pages (empty until tagged in Live).
     *
     * @return list<string>
     */
    public static function labelsFor(Listing $listing): array
    {
        if ($listing->getAttribute('photos_tagged_at') === null) {
            return [];
        }
        $have = DB::connection(Connections::listings())->table('listing_features')->where('listing_id', $listing->id)->pluck('feature')->all();

        $labels = [];
        foreach (self::FEATURES as $key => [$label]) {
            if (in_array($key, $have, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * Features people can search on ("Has: pool"), only once tagging is Live.
     *
     * @return array<string, string>
     */
    public static function searchable(): array
    {
        if ((app(OutsideServices::class)->active('photo_tags')['mode'] ?? null) !== 'live') {
            return [];
        }

        return array_map(fn (array $f): string => $f[0], self::FEATURES);
    }

    /**
     * "Kitchen with kitchen island and stainless appliances"; null when nothing was recognised.
     *
     * @param  list<string>  $features
     */
    public static function altText(?string $room, array $features): ?string
    {
        $labels = array_values(array_map(fn (string $f): string => mb_strtolower(self::FEATURES[$f][0]), array_filter($features, fn (string $f): bool => isset(self::FEATURES[$f]) && $f !== $room)));
        $name = $room !== null && isset(self::ROOMS[$room]) ? self::ROOMS[$room][0] : null;
        if ($name === null && $labels === []) {
            return null;
        }
        $list = count($labels) > 1 ? implode(', ', array_slice($labels, 0, -1)).' and '.end($labels) : ($labels[0] ?? '');
        $text = $name === null ? ucfirst($list) : ($list === '' ? $name : $name.' with '.$list);

        return mb_substr($text, 0, 200);
    }

    /**
     * Matches provider labels (with confidences) to our rooms and features.
     *
     * @param  list<array{0: string, 1: float}>  $roomLabels
     * @param  list<array{0: string, 1: float}>  $featureLabels
     * @return array{room: string|null, features: list<string>}
     */
    public static function match(array $roomLabels, array $featureLabels): array
    {
        $norm = fn (string $s): string => ' '.mb_strtolower(str_replace(['_', '-'], ' ', $s)).' ';
        $room = null;
        usort($roomLabels, fn (array $a, array $b): int => $b[1] <=> $a[1]);
        foreach ($roomLabels as [$label, $conf]) {
            if ($conf < self::MIN_CONFIDENCE) {
                continue;
            }
            foreach (self::ROOMS as $key => [, $words]) {
                foreach ($words as $w) {
                    if (str_contains($norm($label), ' '.str_replace(['_', '-'], ' ', $w).' ')) {
                        $room = $key;
                        break 3;
                    }
                }
            }
        }
        $features = [];
        foreach ($featureLabels as [$label, $conf]) {
            $l = $norm($label);
            if ($conf < self::MIN_CONFIDENCE || array_filter(self::NOT, fn (string $n): bool => str_contains($l, $n)) !== []) {
                continue;
            }
            foreach (self::FEATURES as $key => [, $words]) {
                foreach ($words as $w) {
                    if (str_contains($l, ' '.str_replace(['_', '-'], ' ', $w).' ')) {
                        $features[$key] = true;
                    }
                }
            }
        }
        if ($room === 'garage') {
            unset($features['garage']); // still a feature of the home, gathered from the room below
        }

        // In our vocabulary's order, so the same photo always reads the same.
        return ['room' => $room, 'features' => array_values(array_filter(array_keys(self::FEATURES), fn (string $f): bool => isset($features[$f])))];
    }

    /**
     * Asks the provider about one photo. Null when it could not answer (tried again later).
     *
     * @param  array{provider: string, mode: string, keys: array<string, string>}  $service
     * @return array{room: string|null, features: list<string>}|null
     */
    private function ask(array $service, ?string $url): ?array
    {
        if ($url === null) {
            return null;
        }
        $k = $service['keys'];
        try {
            return match ($service['provider']) {
                'restb' => $this->restb((string) ($k['api_key'] ?? ''), $url),
                'azure_vision' => $this->azure((string) ($k['endpoint'] ?? ''), (string) ($k['api_key'] ?? ''), $url),
                'google_vision' => $this->google((string) ($k['api_key'] ?? ''), $url),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array{room: string|null, features: list<string>}|null */
    private function restb(string $key, string $url): ?array
    {
        $r = Http::timeout(30)->acceptJson()->get('https://api-us.restb.ai/vision/v2/multipredict', [
            'client_key' => $key, 'model_id' => 're_roomtype_global_v2,re_features_v4', 'image_url' => $url]);
        $solutions = $r->json('response.solutions');
        if ($r->failed() || ! is_array($solutions)) {
            return null;
        }
        $top = (array) ($solutions['re_roomtype_global_v2']['top_prediction'] ?? []);
        $rooms = isset($top['label']) ? [[(string) $top['label'], (float) ($top['confidence'] ?? 0)]] : [];
        $features = [];
        foreach ((array) ($solutions['re_features_v4']['detections'] ?? []) as $d) {
            if (is_array($d) && isset($d['label'])) {
                $features[] = [(string) $d['label'], (float) ($d['confidence'] ?? 1)];
            }
        }

        return self::match($rooms, $features);
    }

    /** @return array{room: string|null, features: list<string>}|null */
    private function azure(string $endpoint, string $key, string $url): ?array
    {
        if (! str_starts_with($endpoint, 'https://')) {
            return null;
        }
        $r = Http::timeout(30)->acceptJson()->withHeaders(['Ocp-Apim-Subscription-Key' => $key])
            ->post(rtrim($endpoint, '/').'/computervision/imageanalysis:analyze?api-version=2024-02-01&features=tags', ['url' => $url]);
        $values = $r->json('tagsResult.values');
        if ($r->failed() || ! is_array($values)) {
            return null;
        }
        $labels = array_values(array_filter(array_map(fn ($v): ?array => is_array($v) && isset($v['name']) ? [(string) $v['name'], (float) ($v['confidence'] ?? 0)] : null, $values)));

        return self::match($labels, $labels);
    }

    /** @return array{room: string|null, features: list<string>}|null */
    private function google(string $key, string $url): ?array
    {
        $r = Http::timeout(30)->acceptJson()->post('https://vision.googleapis.com/v1/images:annotate?key='.rawurlencode($key), [
            'requests' => [['image' => ['source' => ['imageUri' => $url]], 'features' => [['type' => 'LABEL_DETECTION', 'maxResults' => 25]]]]]);
        $values = $r->json('responses.0.labelAnnotations');
        if ($r->failed() || ! is_array($values)) {
            return null;
        }
        $labels = array_values(array_filter(array_map(fn ($v): ?array => is_array($v) && isset($v['description']) ? [(string) $v['description'], (float) ($v['score'] ?? 0)] : null, $values)));

        return self::match($labels, $labels);
    }

    /** Our CDN copy when it has a public https address, otherwise the MLS's own address. */
    private function imageUrl(ListingMedia $m): ?string
    {
        $ours = Photos::url($m->storage_path, 'card');
        if (is_string($ours) && str_starts_with($ours, 'https://')) {
            return $ours;
        }

        return str_starts_with($m->source_url, 'https://') ? $m->source_url : null;
    }
}
