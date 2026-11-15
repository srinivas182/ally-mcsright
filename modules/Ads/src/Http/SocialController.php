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
        $tz = $tenants->current()->timezone ?: 'America/New_York';

        return Inertia::render('ads/social', [
            'apps' => $this->social->apps($user),
            'places' => array_map(fn (array $p): array => ['key' => $p['key'], 'channel' => $p['channel'], 'name' => $p['name'], 'brokerage' => $p['brokerage']], $this->social->places($user)),
            'listings' => array_map(fn (array $l): array => ['ulid' => $l['ulid'], 'label' => $l['label']], $ads->listings($user)),
            'start' => is_string($listing) && $listing !== '' ? $this->social->fromListing($user, $listing) : null,
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
        $post = $this->social->schedule(self::user($request), $request->only(['text', 'link', 'image_url', 'listing', 'places', 'post_at']));
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

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
