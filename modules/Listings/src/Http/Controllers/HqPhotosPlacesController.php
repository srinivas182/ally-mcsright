<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Services\Catalog;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Listings\Jobs\TagPhotosAndPlaces;
use Modules\Listings\Media\Photos;
use Modules\Listings\Media\PhotoTags;
use Modules\Listings\Places\NearbyPlaces;

/**
 * HQ → MLS → Photo tags and nearby places (C03): what the providers returned, so HQ can check them in Test mode
 * before switching to Live, and a button for a small run now.
 */
final class HqPhotosPlacesController
{
    public function index(OutsideServices $services): Response
    {
        $db = DB::connection(Connections::listings());
        $photos = $db->table('listing_media')->whereNotNull('tagged_at')->orderByDesc('tagged_at')->limit(12)
            ->get(['storage_path', 'room', 'tags', 'alt_text', 'caption'])
            ->map(fn ($m): array => ['thumb' => Photos::url($m->storage_path, 'thumb'), 'room' => PhotoTags::ROOMS[(string) $m->room][0] ?? 'Not recognised',
                'features' => array_values(array_map(fn (string $f): string => PhotoTags::FEATURES[$f][0], array_filter((array) json_decode((string) $m->tags, true), fn ($f): bool => is_string($f) && isset(PhotoTags::FEATURES[$f])))),
                'alt' => $m->alt_text, 'mls_caption' => $m->caption])->all();
        $places = [];
        foreach ($db->table('listing_places')->orderByDesc('id')->limit(200)->get()->groupBy('listing_id')->take(5) as $listingId => $rows) {
            $l = $db->table('listings')->where('id', (int) $listingId)->first(['street_number', 'street_name', 'city', 'state_or_province']);
            $places[] = ['listing' => $l !== null ? trim($l->street_number.' '.$l->street_name.', '.$l->city.' '.$l->state_or_province) : 'Listing '.$listingId,
                'places' => array_values($rows->sortBy('meters')->map(fn ($r): array => ['name' => (string) $r->name, 'type' => NearbyPlaces::SUBTYPES[(string) $r->subtype] ?? 'Place',
                    'miles' => NearbyPlaces::miles((int) $r->meters)])->all())];
        }
        $service = fn (string $key): array => ['label' => Catalog::get($key)['label'] ?? $key, 'provider' => ($a = $services->active($key)) !== null ? (Catalog::get($key)['providers'][$a['provider']]['label'] ?? $a['provider']) : null,
            'mode' => $a['mode'] ?? 'off'];

        return Inertia::render('hq/mls-photos-places', [
            'tags' => $service('photo_tags') + ['photos' => $db->table('listing_media')->whereNotNull('tagged_at')->count(),
                'listings' => $db->table('listings')->whereNotNull('photos_tagged_at')->count(), 'daily' => ['test' => PhotoTags::DAILY_TEST, 'live' => PhotoTags::DAILY_LIVE], 'latest' => $photos],
            'places' => $service('nearby_places') + ['listings' => $db->table('listings')->whereNotNull('places_at')->count(),
                'checked' => $db->table('listing_places')->distinct()->count('listing_id'), 'daily' => ['test' => NearbyPlaces::DAILY_TEST, 'live' => NearbyPlaces::DAILY_LIVE], 'latest' => $places],
        ]);
    }

    public function run(OutsideServices $services): RedirectResponse
    {
        if ($services->active('photo_tags') === null && $services->active('nearby_places') === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Turn on Photo tagging or Nearby schools and transit in Outside services first (Test or Live).']);

            return back();
        }
        TagPhotosAndPlaces::dispatch(10);
        app(AuditLogger::class)->record('listings.photos_places.run', severity: 'info');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Checking 10 photos and 10 listings now. Refresh in a minute to see the results.']);

        return back();
    }
}
