<?php

declare(strict_types=1);

namespace Modules\ListIq\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Studio\BrandKits;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Enums\Source;
use Modules\ListIq\ListingVideo;
use Modules\ListIq\Marketing;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Models\Prep;
use Modules\ListIq\Photos;
use Modules\ListIq\Showings;
use Modules\ListIq\Tours;
use Modules\Sites\Http\Controllers\SiteController;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;
use Modules\Sites\Visitors\SiteLeads;

/** The listing's own page on the brokerage website and its QR codes (LI2, specs/listings/03). */
final class PublicController
{
    public function scan(string $code, Request $request, Marketing $marketing): RedirectResponse|Response
    {
        $prep = preg_match('/^[a-z0-9]{7}$/', $code) === 1 ? $marketing->scan($code) : null;
        if ($prep === null) {
            return response()->view('sites::missing', ['site' => null], 404);
        }

        return redirect($request->getSchemeAndHttpHost().'/listing/'.$prep->ulid.'?src='.$code, 302)->header('Cache-Control', 'no-store');
    }

    public function show(string $listing, Request $request, Marketing $marketing): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        $prep = Prep::query()->where('ulid', $listing)->first();
        if ($site === null || $prep === null || ! $prep->in_mls) {
            return response()->view('sites::missing', ['site' => $site], 404)->header('Cache-Control', 'public, max-age=60');
        }
        $marketing->viewed($prep);
        $owner = User::query()->find($prep->owner_user_id);
        $photos = Photo::query()->where('listing_id', $prep->id)->where('use', true)->where('status', 'ready')->orderBy('position')->get()
            ->map(fn (Photo $p): array => ['url' => Photos::url($p->path), 'caption' => (string) $p->caption, 'staged' => $p->kind === 'staged'])->all();
        $f = (array) $prep->facts;
        $root = rtrim($request->getSchemeAndHttpHost(), '/');
        $title = $prep->street.($prep->city ? ', '.$prep->city : '');
        $description = mb_substr((string) ($prep->description ?? $title), 0, 160);
        $listingLd = array_filter(['@type' => 'RealEstateListing', 'name' => $title, 'url' => $root.'/listing/'.$prep->ulid, 'description' => $prep->description,
            'image' => array_values(array_filter(array_column(array_slice($photos, 0, 6), 'url'))), 'datePosted' => $prep->updated_at->toDateString(),
            'offers' => isset($f['price']) ? ['@type' => 'Offer', 'price' => (int) $f['price'], 'priceCurrency' => 'USD'] : null,
            'about' => array_filter(['@type' => 'SingleFamilyResidence', 'numberOfRooms' => $f['beds'] ?? null, 'floorSize' => isset($f['sqft']) ? ['@type' => 'QuantitativeValue', 'value' => (int) $f['sqft'], 'unitCode' => 'FTK'] : null,
                'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $prep->street, 'addressLocality' => $prep->city, 'addressRegion' => $prep->state, 'postalCode' => $prep->zip, 'addressCountry' => 'US'])])]);

        return response()->view('listiq::page', [
            'site' => $site, 'current' => '/listing/'.$prep->ulid, 'prep' => $prep, 'photos' => $photos, 'facts' => $f, 'agent' => $owner !== null ? app(BrandKits::class)->forUser($owner) : null,
            'floorPlan' => Photos::url($prep->floor_plan_path),
            // MD1: the 3D or 360-degree tour, and the listing video.
            'tour' => $prep->tour_url !== null ? Tours::read($prep->tour_url) + ['name' => Tours::PROVIDERS[$prep->tour_provider ?? ''] ?? 'Tour'] : null,
            'video' => $prep->video_status === 'ready' && $prep->video_path !== null ? ['url' => Photos::url($prep->video_path), 'poster' => Photos::url($prep->video_poster_path),
                'captions' => Photos::url(ListingVideo::captionsPath($prep->video_path))] : null,
            'booked' => (string) $request->query('booked', ''),
            'showingError' => is_string($request->query('showing_error')) ? mb_substr((string) $request->query('showing_error'), 0, 200) : null, 'src' => preg_match('/^[a-z0-9]{7}$/', (string) $request->query('src')) === 1 ? (string) $request->query('src') : '',
            'seo' => Seo::build($site, $root, '/listing/'.$prep->ulid, $title.' | '.$site->displayName(), $description, $photos[0]['url'] ?? null, true, [[$prep->street, '/listing/'.$prep->ulid]],
                [], [$listingLd], $prep->updated_at->toIso8601String()),
            'chrome' => Chrome::for($site, app(TenantContext::class)->id()),
            ...SiteController::form($request),
        ])->header('Cache-Control', $request->query('sent') !== null || $request->query('error') !== null || $request->query('booked') !== null || $request->query('showing_error') !== null
            ? 'no-store' : 'public, max-age=60, s-maxage=300');
    }

    public function showing(string $listing, Request $request, Showings $showings): RedirectResponse
    {
        $prep = Prep::query()->where('ulid', $listing)->first();
        $back = $request->getSchemeAndHttpHost().'/listing/'.$listing;
        if ($prep === null || ! $prep->in_mls || trim((string) $request->input('website', '')) !== '' || ! RateLimiter::attempt('listing-showing:'.$request->ip(), 10, fn (): bool => true)) {
            return redirect($back.'?booked=1#showing', 303);
        }
        try {
            $s = $showings->request($prep, $request->only(['name', 'email', 'phone', 'brokerage', 'date', 'time']));
        } catch (ValidationException $e) {
            return redirect($back.'?showing_error='.urlencode((string) (collect($e->errors())->flatten()->first() ?? 'Please check the form.')).'#showing', 303);
        }

        return redirect($back.'?booked='.($s->status === 'approved' ? '2' : '1').'#showing', 303);
    }

    public function lead(string $listing, Request $request, Marketing $marketing, SiteLeads $leads): RedirectResponse
    {
        $prep = Prep::query()->where('ulid', $listing)->first();
        $site = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        $back = $request->getSchemeAndHttpHost().'/listing/'.$listing;
        if ($prep === null || $site === null || ! $prep->in_mls || trim((string) $request->input('website', '')) !== '') {
            return redirect($back.'?sent=1#ask', 303);
        }
        try {
            $shown = (int) Crypt::decryptString((string) $request->input('t', ''));
        } catch (\Throwable) {
            $shown = PHP_INT_MAX;
        }
        if (time() - $shown < 3 || ! RateLimiter::attempt('listing-lead:'.$request->ip(), 10, fn (): bool => true)) {
            return redirect($back.'?sent=1#ask', 303);
        }
        $code = preg_match('/^[a-z0-9]{7}$/', (string) $request->input('src')) === 1 ? (string) $request->input('src') : null;
        $kind = $code !== null ? (string) (collect($marketing->codes($prep))->firstWhere('code', $code)['kind'] ?? 'flyer') : null;
        try {
            $leads->capture($site, SiteLeads::source($kind === 'sign' ? 'Yard sign QR' : 'Listing page', 'Ask about '.$prep->street), [
                'name' => (string) $request->input('name', ''), 'email' => (string) $request->input('email', ''), 'phone' => (string) $request->input('phone', ''),
                'intent' => 'buy', 'property' => $prep->street, 'zip' => $prep->zip, 'message' => mb_substr(trim((string) $request->input('message', '')), 0, 1000),
                'sms_consent' => $request->boolean('sms_consent'), 'source' => $kind === 'sign' ? Source::Sign->value : Source::Website->value,
                'landing_url' => '/listing/'.$prep->ulid.($code !== null ? '?src='.$code : ''),
            ], $request, 'listing', null, ['listing' => $prep->ulid, 'code' => $code], User::query()->find($prep->owner_user_id));
        } catch (ValidationException $e) {
            return redirect($back.'?error='.urlencode((string) (collect($e->errors())->flatten()->first() ?? 'Please check the form.')).'#ask', 303);
        }
        $marketing->countLead($prep, $code);

        return redirect($back.'?sent=1#ask', 303);
    }
}
