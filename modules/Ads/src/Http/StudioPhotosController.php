<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\ListingAds;
use Modules\Ads\Studio\StudioPhotos;
use Modules\Ads\Studio\StudioPlans;
use Modules\Billing\Credits\RateCard;
use Modules\Core\Identity\Enums\UserKind;

/** Studio → Enhance photos (specs/grow/13, SA5a): choose a listing's photos, enhance them, add a virtual twilight. */
final class StudioPhotosController
{
    public function index(Request $request, StudioPhotos $photos, StudioPlans $plans, ListingAds $ads): Response
    {
        $user = self::user($request);
        $listings = array_map(fn (array $l): array => ['ulid' => $l['ulid'], 'label' => $l['label']], $ads->listings($user));
        $chosen = (string) $request->query('listing', $listings[0]['ulid'] ?? '');
        $list = $chosen !== '' && in_array($chosen, array_column($listings, 'ulid'), true) ? $photos->forListing($user, $chosen) : [];

        return Inertia::render('studio/photos', [
            'ready' => $plans->current($user) !== null,
            'on' => $photos->ready(),
            'listings' => $listings,
            'listing' => $chosen !== '' ? $chosen : null,
            'photos' => $list,
            'usage' => $plans->usage($user)['enhance'],
            'credits_each' => app(RateCard::class)->rate('studio.enhance')['credits'],
            'max' => StudioPhotos::MAX_PER_REQUEST,
        ]);
    }

    public function store(Request $request, StudioPhotos $photos): RedirectResponse
    {
        $data = $request->validate(['listing' => ['required', 'string', 'size:26'], 'photos' => ['required', 'array', 'max:'.StudioPhotos::MAX_PER_REQUEST],
            'photos.*' => ['integer'], 'twilight' => ['boolean']]);
        $n = $photos->request(self::user($request), (string) $data['listing'], array_values(array_map('intval', $data['photos'])), (bool) ($data['twilight'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => $n === 0 ? 'Those photos are already enhanced.' : "Enhancing {$n} ".($n === 1 ? 'photo' : 'photos').'. They\'re ready in a few minutes.']);

        return redirect('/studio/photos?listing='.$data['listing']);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
