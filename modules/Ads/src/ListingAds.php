<?php

declare(strict_types=1);

namespace Modules\Ads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Jobs\PublishCampaign;
use Modules\Ads\Meta\MetaApi;
use Modules\Ads\Models\Campaign;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\Reels;
use Modules\Ads\Studio\StudioVideo;
use Modules\Ads\Studio\Styles;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\Models\Connection;
use Modules\Core\Agents\AgentCredentials;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Models\Listing;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Funnels\Funnels;
use Modules\Sites\Models\SiteFunnel;
use Modules\Sites\Rendering\Urls;

/**
 * Listing ads (specs/ads/01): which listings a person may advertise, the builder's choices, publishing (from their
 * own Meta ad account), pausing and resuming.
 *
 * - Agents advertise their own listings (MLS rules); owners and staff any listing of the brokerage's agents.
 * - Every ad links to the listing's page on the brokerage website, so leads come in through the website.
 * - The person sees the most it can spend and agrees before anything is published.
 */
final class ListingAds
{
    public const MIN_DAILY_CENTS = 500;

    public const MAX_DAILY_CENTS = 50_000;

    public const MAX_DAYS = 60;

    public function __construct(private readonly TenantContext $tenants, private readonly ListingSearch $search) {}

    /** The person's own Meta Ads connection, if any. */
    public function connection(User $user): ?Connection
    {
        return Connection::query()->where('app_key', 'meta_ads')->where('owner_user_id', $user->id)->first();
    }

    /** @return list<array<string, mixed>> listings this person may advertise (active, with a mapped address) */
    public function listings(User $user): array
    {
        $ids = $this->mlsIds($user);
        $rows = [];
        foreach (array_slice($ids, 0, 50) as $id) {
            foreach ($this->search->search($this->tenants->id(), ['agent_mls_id' => $id, 'per_page' => 50, 'sort' => 'newest'])['results'] as $home) {
                $rows[(string) $home['ulid']] = ['ulid' => (string) $home['ulid'], 'label' => ListingSearch::label($home), 'price' => $home['price'] ?? null, 'photo' => $home['photo'] ?? null];
            }
        }

        return array_values($rows);
    }

    /** @return array<string, mixed>|null the listing, if this person may advertise it */
    public function listing(User $user, string $ulid): ?array
    {
        $home = $this->search->find($this->tenants->id(), $ulid);
        if ($home === null || ! in_array((string) ($home['list_agent_mls_id'] ?? $this->agentOf($ulid)), $this->mlsIds($user), true)) {
            return null;
        }

        return $home;
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function publish(User $by, array $in): Campaign
    {
        $boost = ($in['pay'] ?? 'own') === 'boost';
        if ($boost && ! app(Boost::class)->ready()) {
            throw ValidationException::withMessages(['pay' => 'RightAlly Boost isn\'t switched on yet.']);
        }
        $c = $boost ? null : ($this->connection($by) ?? throw ValidationException::withMessages(['account' => 'Connect Meta Ads first (Apps → Meta Ads), or pay with credits.']));
        if ($boost) {
            $in['account'] = app(Boost::class)->account()['ad_account_id'];
            $in['page'] = app(Boost::class)->account()['page_id'];
        }
        $creative = $this->creativeFrom($by, $in, $boost);
        if ($creative['listing'] !== null) {
            $in['listing'] = $creative['listing'];
        }
        $funnel = is_string($in['funnel'] ?? null) && $in['funnel'] !== '' ? ($this->funnel($by, $in['funnel']) ?? throw ValidationException::withMessages(['funnel' => 'Choose one of your funnels.'])) : null;
        $target = $funnel !== null ? $this->funnelTarget($funnel) : $this->listingTarget($by, (string) ($in['listing'] ?? ''));
        $accounts = $boost ? [(string) $in['account']] : array_column((array) ($c->settings['ad_accounts'] ?? []), 'id');
        $pages = $boost ? [(string) $in['page']] : array_column((array) ($c->settings['pages'] ?? []), 'id');
        $headline = trim((string) ($in['headline'] ?? '')) ?: ($creative['format'] === 'post' ? mb_substr(trim((string) strtok((string) $creative['text'], "\n")), 0, 40) : '');
        $body = trim((string) ($in['body'] ?? '')) ?: ($creative['format'] === 'post' ? mb_substr((string) $creative['text'], 0, 600) : '');
        $goal = ($in['goal'] ?? 'website') === 'form' ? 'form' : 'website';
        $privacy = trim((string) ($in['privacy_url'] ?? ''));
        $daily = (int) round((float) ($in['daily_budget'] ?? 0) * 100);
        $days = (int) ($in['days'] ?? 0);
        $errors = array_filter([
            'account' => in_array((string) ($in['account'] ?? ''), $accounts, true) ? null : 'Choose one of your ad accounts.',
            'page' => in_array((string) ($in['page'] ?? ''), $pages, true) ? null : 'Choose the Facebook Page the ad runs as.',
            'headline' => $headline === '' || mb_strlen($headline) > 40 ? 'Write a headline of up to 40 characters.' : null,
            'body' => $body === '' || mb_strlen($body) > 600 ? 'Write the ad text (up to 600 characters).' : null,
            'daily_budget' => $daily < self::MIN_DAILY_CENTS || $daily > self::MAX_DAILY_CENTS ? 'Choose a daily budget from $5 to $500.' : null,
            'days' => $days < 1 || $days > self::MAX_DAYS ? 'Run it for 1 to 60 days.' : null,
            'agree' => ($in['agree'] ?? false) === true || ($in['agree'] ?? null) === '1' ? null : 'Confirm the budget first.',
            // A Boost form is on RightAlly's Page and its leads are handed to this brokerage (specs/ads/04).
            'goal' => $goal !== 'form' ? null : ($creative['format'] === 'post' ? 'A promoted post sends people to the post\'s link. Choose a design or a Reel for an instant-form ad.'
                : (! $boost && ! $this->formLeadsArrive((string) ($in['page'] ?? '')) ? 'Connect Facebook Lead Ads for this Page first (Apps), so form leads arrive in your CRM.' : null)),
            'privacy_url' => $goal === 'form' && (! str_starts_with($privacy, 'https://') || filter_var($privacy, FILTER_VALIDATE_URL) === false)
                ? 'Add the link to your brokerage\'s privacy policy (https://…): Meta shows it on the form.' : null,
        ]);
        if (($flags = FairHousing::screen($headline.' '.$body, 'ads', 'blocked', 'Ad: '.$target['label'])) !== []) {
            $errors['body'] = 'Fair Housing: please remove "'.implode('", "', $flags).'". Describe the home, not who should live there.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $site = $this->websiteBase() ?? throw ValidationException::withMessages([$target['field'] => 'Publish your brokerage website first: the ad links to a page on it.']);
        $photo = $target['photo'];
        $start = CarbonImmutable::today($by->timezone ?: 'America/New_York');
        $margin = $boost ? app(Boost::class)->marginBps() : 0;
        $campaign = Campaign::query()->create(['user_id' => $by->id, 'connection_id' => $c?->id, 'billing' => $boost ? 'boost' : 'own', 'margin_bps' => $margin,
            'stop_loss' => ($in['stop_loss'] ?? true) !== false, 'ad_account_id' => (string) $in['account'], 'page_id' => (string) $in['page'],
            'listing_ulid' => $target['listing'], 'listing_label' => mb_substr($target['label'], 0, 200), 'site_funnel_id' => $funnel?->id, 'headline' => $headline, 'body' => $body,
            'image_url' => $creative['image'] ?? (str_starts_with($photo, 'http') ? $photo : url($photo)),
            'format' => $creative['format'], 'goal' => $goal, 'images' => $creative['images'], 'video_url' => $creative['video'], 'object_story_id' => $creative['story'],
            'studio_design_id' => $creative['design_id'], 'studio_video_id' => $creative['video_id'], 'social_post_id' => $creative['post_id'],
            'privacy_url' => $goal === 'form' ? $privacy : null,
            'link' => $site.$target['path'].'?'.http_build_query(['utm_source' => 'facebook', 'utm_medium' => 'paid_social']),
            'latitude' => $target['latitude'], 'longitude' => $target['longitude'],
            'radius_miles' => max(MetaApi::MIN_RADIUS_MILES, min(50, (int) ($in['radius'] ?? MetaApi::MIN_RADIUS_MILES))), 'daily_budget_cents' => $daily,
            'starts_on' => $start->toDateString(), 'ends_on' => $start->addDays($days - 1)->toDateString(), 'status' => 'publishing']);
        $campaign->forceFill(['link' => $campaign->link.'&utm_campaign=rightally-'.strtolower($campaign->ulid)])->save();
        if ($boost) {
            // Credits for the most it can spend, plus the margin, are reserved now; what isn't spent comes back.
            $credits = app(Boost::class)->credits($campaign->maxSpendCents(), $margin);
            try {
                app(CreditLedger::class)->charge(Boost::ACTION, $by->id, 'boost:'.$campaign->ulid, $credits);
            } catch (InsufficientCredits $e) {
                $campaign->delete();
                throw ValidationException::withMessages(['pay' => $e->getMessage().' This boost needs '.number_format($credits).' credits.']);
            }
            $campaign->forceFill(['credits_reserved' => $credits])->save();
        }
        app(AuditLogger::class)->record('ads.publish', $campaign, metadata: ['max_spend_cents' => $campaign->maxSpendCents(), 'listing' => $campaign->listing_ulid, 'funnel' => $funnel?->ulid], severity: 'notice');
        PublishCampaign::dispatch($campaign->id);

        return $campaign;
    }

    /**
     * What the ad is made of: an Ally Studio design (its carousel), a Studio Reel or tour (a video ad), a Facebook post
     * already on the person's Page (promoted as it is), or the listing photo. Only the person's own, finished work.
     *
     * @param  array<string, mixed>  $in  studio_design, studio_video, post
     * @return array{format: string, listing: string|null, image: string|null, images: list<string>|null, video: string|null, story: string|null, text: string|null, design_id: int|null, video_id: int|null, post_id: int|null}
     */
    private function creativeFrom(User $by, array $in, bool $boost): array
    {
        $out = ['format' => 'photo', 'listing' => null, 'image' => null, 'images' => null, 'video' => null, 'story' => null, 'text' => null, 'design_id' => null, 'video_id' => null, 'post_id' => null];
        if (is_string($in['studio_video'] ?? null) && $in['studio_video'] !== '') {
            $v = StudioVideo::query()->where('ulid', $in['studio_video'])->where('user_id', $by->id)->where('status', 'ready')->first()
                ?? throw ValidationException::withMessages(['studio_video' => 'Choose one of your finished Reels or tours.']);
            $reels = app(Reels::class);

            return ['format' => 'video', 'listing' => $v->listing_ulid, 'image' => $reels->poster($v), 'video' => $reels->url($v), 'video_id' => $v->id] + $out;
        }
        if (is_string($in['studio_design'] ?? null) && $in['studio_design'] !== '') {
            $d = Design::query()->where('ulid', $in['studio_design'])->where('user_id', $by->id)->where('status', 'ready')->whereNotNull('listing_ulid')->first()
                ?? throw ValidationException::withMessages(['studio_design' => 'Choose one of your finished listing designs.']);
            $designer = app(Designer::class);
            $slides = $designer->carousel($d);

            return ['format' => count($slides) >= 2 ? 'carousel' : 'photo', 'listing' => $d->listing_ulid, 'image' => $designer->url($d, 'portrait'),
                'images' => count($slides) >= 2 ? $slides : null, 'design_id' => $d->id] + $out;
        }
        if (is_string($in['post'] ?? null) && $in['post'] !== '') {
            $post = SocialPost::query()->where('ulid', $in['post'])->where('user_id', $by->id)->whereIn('status', ['posted', 'partial'])->whereNotNull('listing_ulid')->first();
            $fb = $post?->targets()->where('channel', 'facebook')->where('status', 'posted')->whereNotNull('external_id')->first();
            if ($post === null || $fb === null || $boost) {
                throw ValidationException::withMessages(['post' => $boost ? 'A post on your own Page is promoted from your own Meta ad account.'
                    : 'Choose one of your listing posts that went out on your Facebook Page.']);
            }
            if ((string) ($in['page'] ?? '') !== $fb->target_id) {
                throw ValidationException::withMessages(['page' => 'A post is promoted as the Page it was posted on.']);
            }

            return ['format' => 'post', 'listing' => $post->listing_ulid, 'image' => $post->image_url, 'story' => (string) $fb->external_id, 'text' => $post->text, 'post_id' => $post->id] + $out;
        }

        return $out;
    }

    /**
     * What an ad for this listing can be made of: the person's finished Studio designs and videos for it, and their
     * posts about it that went out on their Facebook Page.
     *
     * @return array{designs: list<array{ulid: string, label: string, image: string|null, slides: int}>, videos: list<array{ulid: string, label: string, image: string|null}>, posts: list<array{ulid: string, label: string, image: string|null, page: string}>}
     */
    public function creatives(User $user, string $listing): array
    {
        $designer = app(Designer::class);
        $reels = app(Reels::class);
        $designs = [];
        foreach (Design::query()->where('user_id', $user->id)->where('listing_ulid', $listing)->where('status', 'ready')->latest('id')->limit(9)->get() as $d) {
            $designs[] = ['ulid' => $d->ulid, 'label' => (string) Styles::get($d->collection)['name'], 'image' => $designer->url($d, 'portrait'), 'slides' => count($designer->carousel($d))];
        }
        $videos = [];
        foreach (StudioVideo::query()->where('user_id', $user->id)->where('listing_ulid', $listing)->where('status', 'ready')->latest('id')->limit(6)->get() as $v) {
            $videos[] = ['ulid' => $v->ulid, 'label' => $v->kind === 'tour' ? 'YouTube tour' : 'Reel', 'image' => $reels->poster($v)];
        }
        $posts = [];
        foreach (SocialPost::query()->where('user_id', $user->id)->where('listing_ulid', $listing)->whereIn('status', ['posted', 'partial'])->latest('id')->limit(6)->with('targets')->get() as $p) {
            $fb = $p->targets->first(fn ($t): bool => $t->channel === 'facebook' && $t->status === 'posted' && $t->external_id !== null);
            if ($fb !== null) {
                $posts[] = ['ulid' => $p->ulid, 'label' => mb_substr(strtok($p->text, "\n") ?: 'Post', 0, 60), 'image' => $p->image_url, 'page' => $fb->target_id];
            }
        }

        return ['designs' => $designs, 'videos' => $videos, 'posts' => $posts];
    }

    /**
     * The Facebook Pages whose instant-form leads reach this brokerage's CRM.
     *
     * @return list<string>
     */
    public function formPages(): array
    {
        return array_values(array_map('strval', DB::connection(Connections::control())->table('connect_routes')->where('app_key', 'facebook_leads')
            ->where('tenant_id', $this->tenants->id())->pluck('external_key')->all()));
    }

    /** Whether instant-form leads from this Page reach this brokerage's CRM (Facebook Lead Ads listens to it). */
    private function formLeadsArrive(string $page): bool
    {
        return $page !== '' && DB::connection(Connections::control())->table('connect_routes')->where('app_key', 'facebook_leads')->where('external_key', $page)
            ->where('tenant_id', $this->tenants->id())->exists();
    }

    /** A funnel this person may promote: their own, or (owners and staff) any of the brokerage's. */
    public function funnel(User $user, string $ulid): ?SiteFunnel
    {
        $f = SiteFunnel::query()->where('ulid', $ulid)->where('status', 'live')->first();
        $manager = in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);

        return $f !== null && ($manager || $f->owner_user_id === $user->id) ? $f : null;
    }

    /** @return list<array{ulid: string, label: string}> live funnels this person may promote */
    public function funnels(User $user): array
    {
        $manager = in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);

        $out = [];
        foreach (SiteFunnel::query()->where('status', 'live')->when(! $manager, fn ($q) => $q->where('owner_user_id', $user->id))->orderByDesc('id')->limit(50)->get() as $f) {
            $out[] = ['ulid' => $f->ulid, 'label' => $f->headline];
        }

        return $out;
    }

    /**
     * Where a funnel ad points and whom it reaches: the funnel's page, a photo of a home for sale in its area, and
     * the middle of the homes it shows (housing ads reach everyone within a radius; nothing else narrows them).
     *
     * @return array{field: string, listing: string|null, label: string, path: string, photo: string, latitude: string, longitude: string}
     */
    public function funnelTarget(SiteFunnel $funnel): array
    {
        $homes = $this->search->search($this->tenants->id(), ['per_page' => 24] + Funnels::filters($funnel))['results'];
        $mapped = array_values(array_filter($homes, fn (array $h): bool => is_numeric($h['latitude'] ?? null) && is_numeric($h['longitude'] ?? null)));
        $photo = '';
        foreach ($homes as $h) {
            $photo = (string) ($h['photo'] ?? ($h['photos'][0]['hero'] ?? ''));
            if ($photo !== '') {
                break;
            }
        }
        if ($mapped === [] || $photo === '') {
            throw ValidationException::withMessages(['funnel' => 'There are no homes for sale with photos in this funnel\'s area right now, so the ad has no picture or map area. Try a wider area.']);
        }
        $lat = array_sum(array_map(fn (array $h): float => (float) $h['latitude'], $mapped)) / count($mapped);
        $lng = array_sum(array_map(fn (array $h): float => (float) $h['longitude'], $mapped)) / count($mapped);

        return ['field' => 'funnel', 'listing' => null, 'label' => 'Funnel: '.$funnel->headline, 'path' => '/go/'.$funnel->slug, 'photo' => $photo,
            'latitude' => (string) round($lat, 6), 'longitude' => (string) round($lng, 6)];
    }

    /**
     * @return array{field: string, listing: string|null, label: string, path: string, photo: string, latitude: string, longitude: string}
     */
    private function listingTarget(User $by, string $ulid): array
    {
        $home = $this->listing($by, $ulid) ?? throw ValidationException::withMessages(['listing' => 'Choose one of your listings.']);
        if (($home['latitude'] ?? null) === null || ($home['longitude'] ?? null) === null) {
            throw ValidationException::withMessages(['listing' => 'This listing has no map location, so it can\'t be advertised by area.']);
        }
        $photo = (string) ($home['photo'] ?? ($home['photos'][0]['hero'] ?? ''));
        if ($photo === '') {
            throw ValidationException::withMessages(['listing' => 'This listing has no photos yet, and an ad needs one.']);
        }

        return ['field' => 'listing', 'listing' => (string) $home['ulid'], 'label' => ListingSearch::label($home), 'path' => Urls::listing((string) $home['ulid'], ListingSearch::label($home)),
            'photo' => $photo, 'latitude' => (string) $home['latitude'], 'longitude' => (string) $home['longitude']];
    }

    public function setActive(Campaign $c, User $by, bool $active): void
    {
        $this->guard($c, $by);
        try {
            app(MetaApi::class)->setStatus($c, $this->token($c), $active);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['ad' => $e->getMessage()]);
        }
        $c->forceFill(['status' => $active ? 'active' : 'paused', 'stopped_reason' => null])->save();
        app(AuditLogger::class)->record($active ? 'ads.resume' : 'ads.pause', $c, severity: 'notice');
    }

    /** Stops an ad for good; a Boost gives back its unspent credits straight away. */
    public function end(Campaign $c, User $by): void
    {
        $this->guard($c, $by);
        try {
            app(Lifecycle::class)->end($c, 'Ended by '.$by->name.'.', $this->token($c));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['ad' => $e->getMessage()]);
        }
        app(AuditLogger::class)->record('ads.end', $c, metadata: ['credits_refunded' => $c->credits_refunded], severity: 'notice');
    }

    /** The access token the ad runs with: the person's own Meta sign-in, or RightAlly's Boost account. */
    public function token(Campaign $c): string
    {
        if ($c->isBoost()) {
            return app(Boost::class)->account()['access_token'];
        }
        $conn = $c->connection_id !== null ? Connection::query()->find($c->connection_id) : null;

        return app(ConnectorKit::class)->accessToken(($conn ?? throw new \RuntimeException('Reconnect Meta Ads first.'))->data());
    }

    /** Owners see every ad; others their own. */
    public function canSee(Campaign $c, User $user): bool
    {
        return $c->user_id === $user->id || $user->kind === UserKind::Owner;
    }

    private function guard(Campaign $c, User $by): void
    {
        if ($c->user_id !== $by->id) {
            throw ValidationException::withMessages(['ad' => 'Only the person who made this ad can change it (it runs on their ad account).']);
        }
    }

    /** @return list<string> */
    private function mlsIds(User $user): array
    {
        $creds = app(AgentCredentials::class);
        if (! in_array($user->kind, [UserKind::Owner, UserKind::Staff], true)) {
            return $creds->mlsIds($user->id);
        }

        return array_values(array_unique(User::query()->pluck('id')->flatMap(fn (int $id): array => $creds->mlsIds($id))->all()));
    }

    private function agentOf(string $ulid): string
    {
        return (string) Listing::query()->where('ulid', $ulid)->value('list_agent_mls_id');
    }

    /** The brokerage website's privacy policy (every live website has one at /privacy), for instant forms. */
    public function privacyUrl(): ?string
    {
        $base = $this->websiteBase();

        return $base !== null ? $base.'/privacy' : null;
    }

    private function websiteBase(): ?string
    {
        $host = TenantDomain::query()->where('tenant_id', $this->tenants->id())->where('purpose', 'website')->orderByDesc('is_primary')->orderBy('id')->value('host');

        return is_string($host) ? 'https://'.$host : null;
    }
}
