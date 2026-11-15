<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\AdCopy;
use Modules\Ads\ListingAds;
use Modules\Ads\Models\Campaign;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Listings\Search\ListingSearch;

/** Listing ads: the list with results, the builder, the AI draft, publish, pause and resume (specs/ads/01). */
final class AdsController
{
    public function __construct(private readonly ListingAds $ads) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $app = app(AppCatalog::class)->find('meta_ads');

        return Inertia::render('ads/index', [
            'ready' => $app !== null && app(AppRegistry::class)->ready($app),
            'connected' => $this->ads->connection($user) !== null,
            'campaigns' => Campaign::query()->when($user->kind !== UserKind::Owner, fn ($q) => $q->where('user_id', $user->id))->latest('id')->limit(100)->get()
                ->map(fn (Campaign $c): array => self::row($c, $user))->values()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = self::user($request);
        $conn = $this->ads->connection($user);
        $ulid = (string) $request->query('listing', '');
        $home = $ulid !== '' ? $this->ads->listing($user, $ulid) : null;

        return Inertia::render('ads/new', [
            'connected' => $conn !== null,
            'accounts' => (array) ($conn?->settings['ad_accounts'] ?? []),
            'pages' => (array) ($conn?->settings['pages'] ?? []),
            'listings' => $this->ads->listings($user),
            'listing' => $home === null ? null : ['ulid' => $home['ulid'], 'label' => ListingSearch::label($home), 'price' => $home['price'] ?? null,
                'photo' => $home['photo'] ?? ($home['photos'][0]['hero'] ?? null), 'mapped' => ($home['latitude'] ?? null) !== null],
            'limits' => ['min_daily' => ListingAds::MIN_DAILY_CENTS / 100, 'max_daily' => ListingAds::MAX_DAILY_CENTS / 100, 'max_days' => ListingAds::MAX_DAYS, 'min_radius' => 15],
        ]);
    }

    public function draft(Request $request, AdCopy $copy): JsonResponse
    {
        $user = self::user($request);
        $home = $this->ads->listing($user, (string) $request->input('listing', ''));
        abort_if($home === null, 404);
        try {
            return response()->json($copy->draft($home, $user));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'The AI couldn\'t write a draft just now. Write your own, or try again.'], 503);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $c = $this->ads->publish(self::user($request), $request->only(['listing', 'account', 'page', 'headline', 'body', 'daily_budget', 'days', 'radius']) + ['agree' => $request->boolean('agree')]);
        $c->refresh();
        Inertia::flash('toast', $c->status === 'failed'
            ? ['type' => 'error', 'message' => 'Meta didn\'t accept the ad: '.$c->error]
            : ['type' => 'success', 'message' => $c->status === 'active' ? 'Your ad is live. Meta usually reviews it within a few hours.' : 'Publishing your ad…']);

        return redirect('/ads');
    }

    public function pause(Request $request, string $campaign): RedirectResponse
    {
        $this->ads->setActive($this->find($request, $campaign), self::user($request), false);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Paused. It stops spending straight away.']);

        return back();
    }

    public function resume(Request $request, string $campaign): RedirectResponse
    {
        $this->ads->setActive($this->find($request, $campaign), self::user($request), true);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Running again.']);

        return back();
    }

    private function find(Request $request, string $ulid): Campaign
    {
        $c = Campaign::query()->where('ulid', $ulid)->first();
        abort_unless($c instanceof Campaign && $this->ads->canSee($c, self::user($request)), 404);

        return $c;
    }

    /** @return array<string, mixed> */
    private static function row(Campaign $c, User $viewer): array
    {
        return ['ulid' => $c->ulid, 'listing' => $c->listing_label, 'headline' => $c->headline, 'status' => $c->status, 'error' => $c->error,
            'daily_budget' => $c->daily_budget_cents / 100, 'max_spend' => $c->maxSpendCents() / 100, 'starts_on' => $c->starts_on->toDateString(), 'ends_on' => $c->ends_on->toDateString(),
            'spend' => $c->spend_cents / 100, 'impressions' => $c->impressions, 'clicks' => $c->clicks, 'leads' => $c->leads,
            'cost_per_click' => $c->clicks > 0 ? round($c->spend_cents / 100 / $c->clicks, 2) : null, 'results_at' => $c->results_at?->toIso8601String(),
            'mine' => $c->user_id === $viewer->id, 'by' => $c->user_id === $viewer->id ? null : User::query()->find($c->user_id)?->name];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
