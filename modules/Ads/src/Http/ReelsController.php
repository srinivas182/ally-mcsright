<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\ListingAds;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\MusicLibrary;
use Modules\Ads\Studio\Reels;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioVideo;
use Modules\Core\Identity\Enums\UserKind;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Studio → Reels (specs/grow/13, SA3): make a Reel from a listing design, watch it, download or post it. */
final class ReelsController
{
    public function index(Request $request, Reels $reels, StudioPlans $plans, MusicLibrary $music): Response
    {
        $user = self::user($request);
        $tracks = collect($music->all(true))->keyBy('ulid');
        $listings = array_map(fn (array $l): array => ['ulid' => $l['ulid'], 'label' => $l['label']], app(ListingAds::class)->listings($user));

        return Inertia::render('studio/reels', [
            'ready' => $plans->current($user) !== null,
            'usage' => $plans->usage($user)['reel'],
            'moods' => MusicLibrary::MOODS,
            'tracks' => $tracks->map(fn (array $t): array => ['ulid' => $t['ulid'], 'label' => $t['title'].' · '.$t['artist'], 'mood' => $t['mood']])->values()->all(),
            'listings' => $listings,
            'tour_usage' => $plans->usage($user)['tour'],
            'reels' => StudioVideo::query()->where('user_id', $user->id)->latest('id')->limit(24)->get()->map(function (StudioVideo $v) use ($reels, $tracks, $listings): array {
                $d = $v->design_id !== null ? Design::query()->find($v->design_id) : null;

                return ['ulid' => $v->ulid, 'kind' => $v->kind, 'chapters' => $v->chapters ?? [], 'status' => $v->status, 'error' => $v->error, 'url' => $reels->url($v), 'poster' => $reels->poster($v), 'seconds' => $v->seconds,
                    'used' => $v->used_at !== null, 'moment' => $d !== null ? (Designer::MOMENTS[$d->moment][0] ?? $d->moment) : 'YouTube tour',
                    'title' => (string) ($d->content['headline'] ?? ($v->listing_ulid !== null ? (collect($listings)->firstWhere('ulid', $v->listing_ulid)['label'] ?? '') : '')),
                    'music' => $v->music_ulid !== null ? ($tracks[$v->music_ulid]['title'] ?? null) : null];
            })->values()->all(),
        ]);
    }

    public function store(Request $request, string $design, Reels $reels): RedirectResponse
    {
        $user = self::user($request);
        $d = Design::query()->where('ulid', $design)->where('user_id', $user->id)->firstOrFail();
        $reels->make($user, $d, is_string($request->input('track')) && $request->input('track') !== '' ? (string) $request->input('track') : null,
            (string) $request->input('mood', 'luxury'));

        return redirect('/studio/reels');
    }

    /** A YouTube listing tour from one of the person's listings. */
    public function tour(Request $request, Reels $reels): RedirectResponse
    {
        $data = $request->validate(['listing' => ['required', 'string', 'size:26']]);
        $reels->tour(self::user($request), (string) $data['listing']);

        return redirect('/studio/reels');
    }

    public function download(Request $request, string $reel, Reels $reels): StreamedResponse
    {
        $user = self::user($request);
        $v = StudioVideo::query()->where('ulid', $reel)->where('user_id', $user->id)->firstOrFail();
        $reels->use($v, $user);

        return Storage::disk('public')->download((string) ($v->path ?? abort(404)), 'rightally-'.$v->kind.'-'.strtolower($v->ulid).'.mp4');
    }

    public function post(Request $request, string $reel, Reels $reels): RedirectResponse
    {
        $user = self::user($request);
        $v = StudioVideo::query()->where('ulid', $reel)->where('user_id', $user->id)->firstOrFail();
        $reels->use($v, $user);

        return redirect('/social?reel='.$v->ulid);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
