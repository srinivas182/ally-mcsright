<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\AdCopy;
use Modules\Ads\Boost;
use Modules\Ads\ListingAds;
use Modules\Ads\Models\Campaign;
use Modules\Ads\Returns;
use Modules\Billing\Credits\CreditLedger;
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
            'ready' => ($app !== null && app(AppRegistry::class)->ready($app)) || (($g = app(AppCatalog::class)->find('google_ads')) !== null && app(AppRegistry::class)->ready($g)),
            'boost' => app(Boost::class)->ready(),
            'connected' => $this->ads->connection($user) !== null,
            'google_connected' => $this->ads->googleConnection($user) !== null,
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
        $funnelUlid = (string) $request->query('funnel', '');
        $funnel = $funnelUlid !== '' ? $this->ads->funnel($user, $funnelUlid) : null;
        $funnelTarget = null;
        $funnelError = null;
        if ($funnel !== null) {
            try {
                $funnelTarget = $this->ads->funnelTarget($funnel);
            } catch (ValidationException $e) {
                $funnelError = (string) (collect($e->errors())->flatten()->first() ?? '');
            }
        }

        $boost = app(Boost::class);
        $google = $this->ads->googleConnection($user);
        $googleApp = app(AppCatalog::class)->find('google_ads');

        return Inertia::render('ads/new', [
            'connected' => $conn !== null,
            // C7d-2 (specs/ads/05): Google search and YouTube, from the person's own Google Ads account or Boost on Google.
            'google' => ['ready' => $googleApp !== null && app(AppRegistry::class)->ready($googleApp), 'connected' => $google !== null,
                'accounts' => array_values(array_map(fn ($a): array => ['id' => (string) ($a['id'] ?? ''), 'name' => (string) ($a['name'] ?? '')],
                    array_filter((array) ($google?->settings['ad_accounts'] ?? []), 'is_array'))), 'boost' => $boost->googleReady()],
            'network' => $request->query('network') === 'google' ? 'google' : 'meta',
            'boost' => $boost->ready() ? ['credits_per_dollar' => $boost->credits(100, $boost->marginBps()), 'margin_percent' => $boost->marginBps() / 100,
                'balance' => app(CreditLedger::class)->balanceOf($user->id), 'pool' => app(CreditLedger::class)->policy()['agents_use_pool']] : null,
            'accounts' => (array) ($conn?->settings['ad_accounts'] ?? []),
            'pages' => (array) ($conn?->settings['pages'] ?? []),
            'listings' => $this->ads->listings($user),
            'funnels' => $this->ads->funnels($user),
            'funnel' => $funnel === null ? null : ['ulid' => $funnel->ulid, 'label' => $funnel->headline, 'photo' => $funnelTarget['photo'] ?? null, 'error' => $funnelError,
                'headline' => mb_substr($funnel->headline, 0, 40), 'body' => (string) $funnel->intro],
            'listing' => $home === null ? null : ['ulid' => $home['ulid'], 'label' => ListingSearch::label($home), 'price' => $home['price'] ?? null,
                'photo' => $home['photo'] ?? ($home['photos'][0]['hero'] ?? null), 'mapped' => ($home['latitude'] ?? null) !== null],
            // C4c: what the ad can be made of for this listing (Studio designs, Reels and tours, posts on the person's Page) and instant forms.
            'creatives' => $home === null ? null : $this->ads->creatives($user, (string) $home['ulid']),
            'chosen' => ['studio_design' => (string) $request->query('design', ''), 'studio_video' => (string) $request->query('reel', ''), 'post' => (string) $request->query('post', '')],
            'form_pages' => $this->ads->formPages(),
            'privacy_url' => Campaign::query()->where('user_id', $user->id)->whereNotNull('privacy_url')->latest('id')->value('privacy_url') ?? $this->ads->privacyUrl(),
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
        $c = $this->ads->publish(self::user($request), $request->only(['listing', 'funnel', 'account', 'page', 'headline', 'body', 'daily_budget', 'days', 'radius', 'pay', 'studio_design', 'studio_video', 'post', 'goal', 'privacy_url', 'network', 'placement'])
            + ['agree' => $request->boolean('agree'), 'stop_loss' => $request->boolean('stop_loss', true)]);
        $c->refresh();
        Inertia::flash('toast', $c->status === 'failed'
            ? ['type' => 'error', 'message' => ($c->channel === 'google' ? 'Google' : 'Meta').' didn\'t accept the ad: '.$c->error]
            : ['type' => 'success', 'message' => $c->status === 'active' ? 'Your ad is live. '.($c->channel === 'google' ? 'Google' : 'Meta').' usually reviews it within a few hours.' : 'Publishing your ad…']);

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

    public function end(Request $request, string $campaign): RedirectResponse
    {
        $c = $this->find($request, $campaign);
        $this->ads->end($c, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $c->isBoost() && $c->credits_refunded > 0
            ? 'Ended. '.number_format($c->credits_refunded).' unspent credits are back in your balance.' : 'Ended. It won\'t spend anything more.']);

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
        $returns = app(Returns::class)->for($c);

        return ['ulid' => $c->ulid, 'listing' => $c->listing_label, 'billing' => $c->billing,
            'network' => $c->channel === 'google' ? ($c->format === 'video' ? 'YouTube' : 'Google search') : 'Facebook and Instagram', 'credits_reserved' => $c->credits_reserved, 'credits_refunded' => $c->credits_refunded,
            'stopped_reason' => $c->stopped_reason, 'stop_loss' => $c->stop_loss, 'pending_commission' => $returns['pending_cents'] / 100, 'earned_commission' => $returns['earned_cents'] / 100,
            'return' => $c->spend_cents > 0 && $returns['earned_cents'] > 0 ? round($returns['earned_cents'] / $c->spend_cents, 1) : null, 'headline' => $c->headline, 'status' => $c->status, 'error' => $c->error,
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
