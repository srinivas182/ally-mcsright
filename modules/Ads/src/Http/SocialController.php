<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\ListingAds;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialPosts;
use Modules\Ads\Social\SocialTarget;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\Reels;
use Modules\Ads\Studio\StudioVideo;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;

/** Grow → Social posts (specs/connect/19, C7d-1): write once, choose where, post now or schedule, see results. */
final class SocialController
{
    public function __construct(private readonly SocialPosts $social) {}

    public function index(Request $request, ListingAds $ads, TenantContext $tenants): Response
    {
        $user = self::user($request);
        $listing = $request->query('listing');
        $design = is_string($request->query('design')) ? Design::query()->where('ulid', $request->query('design'))->where('user_id', $user->id)->where('status', 'ready')->first() : null;
        $reel = is_string($request->query('reel')) ? StudioVideo::query()->where('ulid', $request->query('reel'))->where('user_id', $user->id)->where('status', 'ready')->first() : null;
        $tz = $tenants->current()->timezone ?: 'America/New_York';
        $start = $design !== null ? $this->fromDesign($user, $design) : (is_string($listing) && $listing !== '' ? $this->social->fromListing($user, $listing) : null);
        if ($reel !== null && $reel->kind === 'tour' && $reel->listing_ulid !== null) {
            // A listing tour: the listing's words and page, the chapters (YouTube shows them), the video and its first frame.
            $base = $this->social->fromListing($user, $reel->listing_ulid);
            $start = ['images' => []] + $base;
            $start['text'] = 'Tour: '.$base['text']."\n\n".Reels::chapterLines($reel);
            $start['image'] = app(Reels::class)->poster($reel);
            $start['video'] = app(Reels::class)->url($reel);
        } elseif ($reel !== null && ($from = Design::query()->find($reel->design_id)) !== null) {
            // A Reel: the design's caption and link, the video, and its first frame as the cover (for places that take a photo).
            $start = ['images' => []] + $this->fromDesign($user, $from);
            $start['image'] = app(Reels::class)->poster($reel);
            $start['video'] = app(Reels::class)->url($reel);
        }

        return Inertia::render('ads/social', [
            'apps' => $this->social->apps($user),
            'places' => array_map(fn (array $p): array => ['key' => $p['key'], 'channel' => $p['channel'], 'name' => $p['name'], 'brokerage' => $p['brokerage']], $this->social->places($user)),
            'listings' => array_map(fn (array $l): array => ['ulid' => $l['ulid'], 'label' => $l['label']], $ads->listings($user)),
            'start' => $start,
            'timezone' => $tz,
            'posts' => SocialPost::query()->where('user_id', $user->id)->with('targets')->latest('id')->limit(30)->get()->map(fn (SocialPost $p): array => [
                'ulid' => $p->ulid, 'text' => mb_substr($p->text, 0, 280), 'status' => $p->status, 'post_at' => $p->post_at->setTimezone($tz)->format('M j, g:i A'),
                'cancellable' => $p->status === 'scheduled' && $p->post_at->isFuture(),
                'targets' => $p->targets->map(fn (SocialTarget $t): array => ['channel' => $t->channel, 'name' => $t->name, 'status' => $t->status, 'url' => $t->url, 'error' => $t->error])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $post = $this->social->schedule(self::user($request), $request->only(['text', 'link', 'image_url', 'images', 'video_url', 'listing', 'places', 'post_at']));
        Inertia::flash('toast', ['type' => 'success', 'message' => $post->post_at->isFuture() ? 'Scheduled.' : 'Posting now: results show below in a moment.']);

        return redirect('/social');
    }

    public function cancel(Request $request, string $post): RedirectResponse
    {
        $user = self::user($request);
        $this->social->cancel(SocialPost::query()->where('ulid', $post)->where('user_id', $user->id)->firstOrFail(), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cancelled.']);

        return back();
    }

    /**
     * A post started from an Ally Studio design: its portrait image, a caption to edit and, for a listing, its page.
     *
     * @return array{listing: string, text: string, link: string, image: string|null, images: list<string>}
     */
    private function fromDesign(User $user, Design $design): array
    {
        $designer = app(Designer::class);
        $link = $design->listing_ulid !== null ? ($this->social->fromListing($user, $design->listing_ulid)['link']) : '';

        return ['listing' => $design->listing_ulid ?? '', 'text' => $designer->caption($design), 'link' => $link, 'image' => $designer->url($design, 'portrait'),
            'images' => count($c = $designer->carousel($design)) >= 2 ? $c : []];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
