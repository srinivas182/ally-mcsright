<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Ads\ListingAds;
use Modules\Ads\Meta\MetaApi;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialPosts;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Models\Listing;
use Modules\Transactions\Models\Deal;
use Throwable;

/**
 * Ally Studio Autopilot (specs/grow/13, SA2): Studio notices moments (a new listing, a price improvement, under
 * contract, sold, the weekly market, holidays), designs the post, writes the caption (AI, the agent's voice,
 * Spanish when chosen, local hashtags, Fair Housing checked) and picks a good time. In "Approve first" mode the
 * person approves each post (from Studio, the daily brief or Your AI Assistant); in "Autopilot" mode it is scheduled
 * by itself. Each moment is planned once; each post is counted against the allowance when it is scheduled.
 */
final class Autopilot
{
    public const FEATURE = 'ads.studio_caption';

    public const MODES = ['off' => 'Off', 'approve' => 'Approve first', 'auto' => 'Autopilot'];

    public const KINDS = ['listings' => 'Listing news (new, price, under contract, sold), with launch campaigns', 'market' => 'Weekly market update (Mondays)',
        'closings' => 'Closings (only after your client says yes)', 'milestones' => 'Milestones and work anniversaries', 'holidays' => 'Holiday greetings',
        'recruiting' => 'Recruiting (brokerage package, owners and staff)'];

    /** Closed deals in a year that are worth celebrating. */
    public const MILESTONES = [5, 10, 25, 50, 100, 150, 200];

    public const DEFAULT_TIMES = ['09:30', '18:00'];

    public function __construct(private readonly TenantContext $tenants, private readonly Designer $designer, private readonly StudioPlans $plans) {}

    /** @return array{mode: string, places: list<string>|null, times: list<string>, per_week: int, moments: list<string>, learn_voice: bool, voice: list<string>, auto_enhance: bool, auto_reels: bool, extra_credits: bool} */
    public function settings(User $user): array
    {
        $r = DB::connection(Connections::cell())->table('studio_autopilot')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->first();
        $json = fn (mixed $v): ?array => is_string($v) && is_array($d = json_decode($v, true)) ? array_values(array_map('strval', $d)) : null;

        return ['mode' => (string) ($r->mode ?? 'approve'), 'places' => $json($r->places ?? null), 'times' => $json($r->times ?? null) ?? self::DEFAULT_TIMES,
            'per_week' => (int) ($r->per_week ?? 5), 'moments' => $json($r->moments ?? null) ?? array_keys(self::KINDS), 'learn_voice' => (bool) ($r->learn_voice ?? false),
            'voice' => $json($r->voice ?? null) ?? [], 'auto_enhance' => (bool) ($r->auto_enhance ?? true), 'auto_reels' => (bool) ($r->auto_reels ?? true), 'extra_credits' => (bool) ($r->extra_credits ?? false)];
    }

    /** @param array<string, mixed> $in mode, places[], times[], per_week, moments[], learn_voice, auto_enhance, auto_reels, extra_credits */
    public function save(User $user, array $in): void
    {
        $times = array_values(array_unique(array_filter(array_map('strval', (array) ($in['times'] ?? [])), fn (string $t): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1)));
        $places = array_key_exists('places', $in) && $in['places'] !== null ? array_values(array_intersect(array_map('strval', (array) $in['places']),
            array_column(app(SocialPosts::class)->places($user), 'key'))) : null;
        $values = ['mode' => isset(self::MODES[$in['mode'] ?? '']) ? (string) $in['mode'] : 'approve', 'places' => $places !== null ? json_encode($places) : null,
            'times' => json_encode($times !== [] ? array_slice($times, 0, 4) : self::DEFAULT_TIMES), 'per_week' => max(1, min(14, (int) ($in['per_week'] ?? 5))),
            'moments' => json_encode(array_values(array_intersect(array_keys(self::KINDS), array_map('strval', (array) ($in['moments'] ?? []))))),
            'learn_voice' => (bool) ($in['learn_voice'] ?? false), 'auto_enhance' => (bool) ($in['auto_enhance'] ?? true), 'auto_reels' => (bool) ($in['auto_reels'] ?? true), 'extra_credits' => (bool) ($in['extra_credits'] ?? false),
            'updated_at' => now()];
        if (! $values['learn_voice']) {
            $values += ['voice' => null, 'voice_at' => null]; // forget the samples when the person says no
        }
        if ($values['mode'] === 'auto' && $this->rules()['require_review'] && $user->kind === UserKind::Agent) {
            $values['mode'] = 'approve'; // the brokerage reviews agents' posts
        }
        DB::connection(Connections::cell())->table('studio_autopilot')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id], $values + ['created_at' => now()]);
        app(AuditLogger::class)->record('studio.autopilot_saved', metadata: ['mode' => $values['mode']]);
    }

    /** Hourly, for one person: notice new moments, then move designed posts on. Returns how many moments were new. */
    public function runFor(User $user): int
    {
        $s = $this->settings($user);
        if ($s['mode'] === 'off' || $this->plans->current($user) === null) {
            return 0;
        }
        $new = 0;
        if (in_array('listings', $s['moments'], true)) {
            $new += $this->listingMoments($user);
        }
        $local = CarbonImmutable::now($this->tz());
        if (in_array('market', $s['moments'], true) && $local->isMonday()) {
            $new += $this->marketMoment($user, $local);
        }
        if (in_array('holidays', $s['moments'], true)) {
            $new += $this->holidayMoment($user, $local);
        }
        if (in_array('closings', $s['moments'], true)) {
            $new += $this->closingMoments($user);
        }
        if (in_array('milestones', $s['moments'], true)) {
            $new += $this->milestoneMoments($user, $local);
        }
        if (in_array('recruiting', $s['moments'], true) && $local->day === 1) {
            $new += $this->recruitingMoment($user, $local);
        }
        if ($s['learn_voice'] && $this->plans->has($user, 'voice_learning')) {
            $this->learnVoice($user);
        }
        $this->advance($user);

        return $new;
    }

    /** Moves designed posts on: caption and time, then waiting for approval or scheduled. */
    public function advance(User $user): void
    {
        $s = $this->settings($user);
        // Posts waiting for a new listing's photo enhancement: designed once the photos are ready (or after 30 minutes, with the originals).
        foreach (QueuedPost::query()->where('user_id', $user->id)->where('status', 'enhancing')->orderBy('id')->get() as $item) {
            if ($item->listing_ulid !== null && app(StudioPhotos::class)->pending($item->listing_ulid) && $item->created_at->gt(CarbonImmutable::now()->subMinutes(30))) {
                continue;
            }
            $item->forceFill(['status' => 'designing'])->save();
            $this->design($item, $user, self::input($item));
        }
        foreach (QueuedPost::query()->where('user_id', $user->id)->where('status', 'designing')->orderBy('id')->get() as $item) {
            $designs = Design::query()->where('batch', (string) $item->batch)->orderBy('id')->get();
            $ready = $designs->firstWhere('status', 'ready');
            if ($ready === null) {
                if ($designs->isNotEmpty() && $designs->every(fn (Design $d): bool => $d->status === 'failed')) {
                    $item->forceFill(['status' => 'failed', 'reason' => mb_substr((string) ($designs->first()?->checks[0] ?? 'The designs did not pass the checks.'), 0, 300)])->save();
                } elseif ($item->created_at->lt(CarbonImmutable::now()->subHours(3))) {
                    $item->forceFill(['status' => 'failed', 'reason' => 'The designs took too long. Studio will try the next moment.'])->save();
                }

                continue;
            }
            $this->caption($item, $ready, $user);
            $item->forceFill(['design_id' => $ready->id, 'post_at' => $item->post_at ?? $this->slot($user, $s), 'status' => 'awaiting'])->save();
            if ($item->moment === 'just_listed' && $item->video_id === null) {
                $this->launchReel($item, $ready, $user, $s);
            }
            if ($s['mode'] === 'auto' && ! $item->needs_client_ok) {
                $this->schedule($item, $user);
            }
        }
        // Reels for new listings: posted once the video is made, a day after the listing post.
        foreach (QueuedPost::query()->where('user_id', $user->id)->where('status', 'filming')->orderBy('id')->get() as $item) {
            $video = StudioVideo::query()->find($item->video_id);
            if ($video === null || $video->status === 'failed') {
                $item->forceFill(['status' => 'failed', 'reason' => mb_substr((string) ($video->error ?? 'The Reel could not be made.'), 0, 300)])->save();

                continue;
            }
            $design = Design::query()->find($item->design_id);
            if ($video->status !== 'ready' || $design === null) {
                continue;
            }
            $this->caption($item, $design, $user);
            $item->forceFill(['status' => 'awaiting'])->save();
            if ($s['mode'] === 'auto') {
                $this->schedule($item, $user);
            }
        }
    }

    /**
     * The person approves a planned post (optionally with their own words or time): it is scheduled.
     *
     * @param  array<string, mixed>  $in  caption, post_at (local "Y-m-d\TH:i")
     */
    public function approve(QueuedPost $item, User $by, array $in = []): QueuedPost
    {
        abort_unless($item->user_id === $by->id, 404);
        if ($item->status !== 'awaiting') {
            throw ValidationException::withMessages(['post' => 'This post has already been scheduled or skipped.']);
        }
        if ($item->needs_client_ok && ! (bool) ($in['client_ok'] ?? false)) {
            throw ValidationException::withMessages(['client_ok' => 'Please confirm your client said yes to this post.']);
        }
        if (is_string($in['caption'] ?? null) && trim($in['caption']) !== '') {
            $caption = mb_substr(trim(strip_tags($in['caption'])), 0, 2_000);
            if (($flags = FairHousing::screen($caption, 'ads', 'blocked', 'Ally Studio caption')) !== []) {
                throw ValidationException::withMessages(['caption' => 'Fair Housing: please remove "'.implode('", "', $flags).'".']);
            }
            $item->forceFill(['caption' => $caption, 'caption_es' => null, 'hashtags' => []]);
        }
        if (is_string($in['post_at'] ?? null) && $in['post_at'] !== '') {
            try {
                $item->forceFill(['post_at' => CarbonImmutable::parse($in['post_at'], $this->tz())->utc()]);
            } catch (Throwable) {
                throw ValidationException::withMessages(['post_at' => 'Choose a date and time.']);
            }
        }
        $item->save();

        return $this->schedule($item, $by, true);
    }

    /**
     * Posts agents approved that wait for the brokerage's review (owners and staff), when the brokerage asks for it.
     *
     * @return Collection<int, QueuedPost>
     */
    public function forReview(): Collection
    {
        return QueuedPost::query()->where('status', 'review')->orderBy('post_at')->limit(100)->get();
    }

    /** The brokerage approves (it is scheduled for the agent) or sends it back (skipped, with why). */
    public function review(QueuedPost $item, User $by, bool $ok, string $why = ''): QueuedPost
    {
        abort_unless(in_array($by->kind, [UserKind::Owner, UserKind::Staff], true), 403);
        if ($item->status !== 'review') {
            throw ValidationException::withMessages(['post' => 'This post has already been reviewed.']);
        }
        $item->forceFill(['reviewed_by' => $by->id])->save();
        $agent = User::query()->findOrFail($item->user_id);
        app(AuditLogger::class)->record($ok ? 'studio.review_approved' : 'studio.review_declined', metadata: ['post' => $item->ulid, 'agent' => $agent->id], severity: 'notice');
        if (! $ok) {
            $item->forceFill(['status' => 'skipped', 'reason' => mb_substr('Not approved by the brokerage'.($why !== '' ? ': '.trim(strip_tags($why)) : '.'), 0, 300)])->save();

            return $item;
        }

        return $this->schedule($item, $agent, true, true);
    }

    /** Moves a planned post to another day, keeping its time (the calendar). */
    public function move(QueuedPost $item, User $by, string $date): QueuedPost
    {
        abort_unless($item->user_id === $by->id, 404);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! in_array($item->status, ['awaiting', 'review', 'scheduled', 'designing', 'enhancing', 'filming'], true)) {
            throw ValidationException::withMessages(['date' => 'This post can\'t be moved.']);
        }
        $local = ($item->post_at ?? CarbonImmutable::now())->setTimezone($this->tz());
        $at = CarbonImmutable::parse($date.' '.$local->format('H:i'), $this->tz());
        if ($at->lt(CarbonImmutable::now()->addMinutes(10)) || $at->gt(CarbonImmutable::now()->addDays(60))) {
            throw ValidationException::withMessages(['date' => 'Choose a day in the next 60 days.']);
        }
        if ($item->status === 'scheduled' && $item->social_post_id !== null) {
            $moved = SocialPost::query()->whereKey($item->social_post_id)->where('status', 'scheduled')->update(['post_at' => $at->utc()]);
            if ($moved === 0) {
                throw ValidationException::withMessages(['date' => 'This post has already gone out.']);
            }
        }
        $item->forceFill(['post_at' => $at->utc()])->save();

        return $item;
    }

    /** @return array{require_review: bool, caption_disclaimer: string} */
    public function rules(): array
    {
        $r = DB::connection(Connections::cell())->table('studio_rules')->where('tenant_id', $this->tenants->id())->first();

        return ['require_review' => (bool) ($r->require_review ?? false), 'caption_disclaimer' => (string) ($r->caption_disclaimer ?? '')];
    }

    /** @param array<string, mixed> $in require_review, caption_disclaimer */
    public function saveRules(User $by, array $in): void
    {
        abort_unless($by->kind === UserKind::Owner, 403);
        $disclaimer = mb_substr(trim(strip_tags((string) ($in['caption_disclaimer'] ?? ''))), 0, 300);
        DB::connection(Connections::cell())->table('studio_rules')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['require_review' => (bool) ($in['require_review'] ?? false), 'caption_disclaimer' => $disclaimer !== '' ? $disclaimer : null, 'updated_by' => $by->id,
                'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('studio.rules_saved', metadata: ['require_review' => (bool) ($in['require_review'] ?? false)], severity: 'notice');
    }

    public function skip(QueuedPost $item, User $by): void
    {
        abort_unless($item->user_id === $by->id, 404);
        if (in_array($item->status, ['enhancing', 'designing', 'filming', 'awaiting'], true)) {
            $item->forceFill(['status' => 'skipped'])->save();
        }
    }

    /** The words that go out: caption, Spanish, hashtags. */
    public function text(QueuedPost $item): string
    {
        $tags = array_map(fn (string $t): string => '#'.$t, $item->hashtags ?? []);

        $enhanced = $item->design_id !== null && ! empty(Design::query()->find($item->design_id)?->content['enhanced']);

        return trim(implode("\n\n", array_filter([(string) $item->caption, (string) $item->caption_es, implode(' ', $tags), $enhanced ? StudioPhotos::LABEL.'.' : '',
            $this->rules()['caption_disclaimer']])));
    }

    private function listingMoments(User $user): int
    {
        $db = DB::connection(Connections::cell());
        $tenant = $this->tenants->id();
        $ulids = array_column(app(ListingAds::class)->listings($user), 'ulid');
        if ($ulids === []) {
            return 0;
        }
        $seen = $db->table('studio_listing_seen')->where('tenant_id', $tenant)->where('user_id', $user->id)->get()->keyBy('listing_ulid');
        $new = 0;
        foreach (Listing::query()->whereIn('ulid', $ulids)->get(['ulid', 'standard_status', 'list_price_cents', 'on_market_date']) as $l) {
            $was = $seen[$l->ulid] ?? null;
            $status = (string) $l->standard_status;
            $moment = null;
            $key = null;
            if ($was === null) {
                if ($status === 'Active' && $l->on_market_date !== null && $l->on_market_date->gte(CarbonImmutable::now()->subDays(7))) {
                    [$moment, $key] = ['just_listed', 'listing:'.$l->ulid.':just_listed'];
                } elseif ($status === 'Coming Soon') {
                    [$moment, $key] = ['coming_soon', 'listing:'.$l->ulid.':coming_soon'];
                }
            } elseif ($was->status !== $status) {
                [$moment, $key] = match (true) {
                    in_array($status, ['Pending', 'Active Under Contract'], true) => ['under_contract', 'listing:'.$l->ulid.':under_contract'],
                    $status === 'Closed' => ['just_sold', 'listing:'.$l->ulid.':just_sold'],
                    $status === 'Active' && $was->status === 'Coming Soon' => ['just_listed', 'listing:'.$l->ulid.':just_listed'],
                    default => [null, null],
                };
            } elseif ($status === 'Active' && $was->price_cents !== null && $l->list_price_cents !== null && (int) $l->list_price_cents < (int) $was->price_cents) {
                [$moment, $key] = ['price_improved', 'listing:'.$l->ulid.':price:'.$l->list_price_cents];
            }
            $db->table('studio_listing_seen')->updateOrInsert(['tenant_id' => $tenant, 'user_id' => $user->id, 'listing_ulid' => $l->ulid],
                ['status' => $status, 'price_cents' => $l->list_price_cents, 'updated_at' => now(), 'created_at' => now()]);
            if ($moment !== null && $key !== null) {
                if (in_array($moment, ['just_listed', 'coming_soon'], true) && $this->settings($user)['auto_enhance']
                    && ! QueuedPost::query()->where('user_id', $user->id)->where('source_key', $key)->exists()) {
                    app(StudioPhotos::class)->auto($user, $l->ulid); // the best photos first, from the allowance only
                }
                $made = $this->enqueue($user, $key, $moment, ['moment' => $moment, 'listing' => $l->ulid]);
                $new += $made;
                if ($made === 1 && $moment === 'just_listed' && $this->plans->has($user, 'launch_campaign')) {
                    // The listing launch campaign: the inside, then the neighbourhood, across the first week.
                    $s = $this->settings($user);
                    $this->enqueue($user, 'launch:'.$l->ulid.':inside', 'listing_feature', ['moment' => 'listing_feature', 'listing' => $l->ulid, 'photo_from' => 3],
                        $this->slot($user, $s, CarbonImmutable::now()->addDays(2)));
                    $this->enqueue($user, 'launch:'.$l->ulid.':area', 'neighbourhood', ['moment' => 'neighbourhood', 'listing' => $l->ulid, 'photo_from' => 1],
                        $this->slot($user, $s, CarbonImmutable::now()->addDays(4)));
                }
            }
        }

        return $new;
    }

    private function marketMoment(User $user, CarbonImmutable $local): int
    {
        $key = 'market:'.$local->format('o-\WW');
        if (QueuedPost::query()->where('user_id', $user->id)->where('source_key', $key)->exists()) {
            return 0;
        }
        $mine = Listing::query()->whereIn('ulid', array_column(app(ListingAds::class)->listings($user), 'ulid'))->whereNotNull('city')->pluck('city')->countBy();
        $city = $mine->isNotEmpty() ? (string) $mine->sortDesc()->keys()->first() : null;
        if ($city === null) {
            return 0;
        }
        $area = Listing::query()->visibleTo($this->tenants->id())->where('city', $city)->where('standard_status', 'Active');
        $prices = (clone $area)->whereNotNull('list_price_cents')->orderBy('list_price_cents')->pluck('list_price_cents')->map(fn ($c): int => (int) $c)->values();
        if ($prices->count() < 3) {
            return 0; // not enough to say something true
        }
        $fresh = (clone $area)->where('on_market_date', '>=', CarbonImmutable::now()->subDays(7)->toDateString())->count();
        $median = (int) $prices->get(intdiv($prices->count(), 2), 0);
        $dom = (int) round((float) ((clone $area)->whereNotNull('days_on_market')->avg('days_on_market') ?? 0));
        $stats = [['value' => (string) $fresh, 'label' => 'New this week'], ['value' => '$'.self::short($median), 'label' => 'Median price'],
            ['value' => (string) $dom, 'label' => 'Avg days on market']];

        return $this->enqueue($user, $key, 'market_update', ['moment' => 'market_update', 'headline' => $city.' this week', 'stats' => $stats,
            'body' => $prices->count().' homes for sale right now.']);
    }

    private function holidayMoment(User $user, CarbonImmutable $local): int
    {
        foreach (self::holidays((int) $local->year) as $date => [$key, $headline, $body]) {
            $day = CarbonImmutable::parse($date, $this->tz());
            if ($day->gte($local->startOfDay()) && $day->lte($local->startOfDay()->addDays(2))) {
                $made = $this->enqueue($user, 'holiday:'.$key.':'.$local->year, 'holiday', ['moment' => 'holiday', 'headline' => $headline, 'body' => $body],
                    $day->setTime(9, 30)->utc());

                return $made;
            }
        }

        return 0;
    }

    /** A deal closed in the last week becomes a "Welcome home" post that waits for the client's OK (address hidden). */
    private function closingMoments(User $user): int
    {
        $new = 0;
        foreach (Deal::query()->where('agent_user_id', $user->id)->where('status', 'closed')
            ->where('closed_on', '>=', CarbonImmutable::now()->subDays(7)->toDateString())->get() as $deal) {
            $made = $this->enqueue($user, 'closing:'.$deal->ulid, 'just_closed', ['moment' => 'just_closed', 'headline' => 'Another happy closing'.($deal->city ? ' in '.$deal->city : ''),
                'body' => 'Thank you for trusting me with one of life\'s biggest moves.']);
            if ($made === 1) {
                QueuedPost::query()->where('user_id', $user->id)->where('source_key', 'closing:'.$deal->ulid)->update(['needs_client_ok' => true]);
            }
            $new += $made;
        }

        return $new;
    }

    /** Closings this year reaching a milestone, and the work anniversary with the brokerage. */
    private function milestoneMoments(User $user, CarbonImmutable $local): int
    {
        $new = 0;
        $closed = Deal::query()->where('agent_user_id', $user->id)->where('status', 'closed')
            ->where('closed_on', '>=', $local->startOfYear()->toDateString())->count();
        $reached = collect(self::MILESTONES)->filter(fn (int $m): bool => $closed >= $m)->last();
        if (is_int($reached)) {
            $new += $this->enqueue($user, 'milestone:'.$local->year.':'.$reached, 'milestone', ['moment' => 'milestone', 'headline' => $reached.' homes closed in '.$local->year,
                'body' => 'Thank you to every client who made it possible.']);
        }
        $start = $user->created_at !== null ? CarbonImmutable::parse($user->created_at)->setTimezone($this->tz()) : null;
        $years = $start !== null ? (int) $start->diffInYears($local) : 0;
        if ($start !== null && $years >= 1 && $start->format('m-d') === $local->format('m-d')) {
            $brokerage = (string) $this->tenants->current()->name;
            $new += $this->enqueue($user, 'anniversary:'.$local->year, 'milestone', ['moment' => 'milestone', 'headline' => $years.' '.($years === 1 ? 'year' : 'years').' with '.$brokerage,
                'body' => 'Grateful for every home, every family and every friend along the way.']);
        }

        return $new;
    }

    /** On the 1st, a recruiting post for owners and staff with the brokerage package. */
    private function recruitingMoment(User $user, CarbonImmutable $local): int
    {
        if (! in_array($user->kind, [UserKind::Owner, UserKind::Staff], true) || ! $this->plans->has($user, 'recruiting')) {
            return 0;
        }
        $closed = Deal::query()->where('status', 'closed')->where('closed_on', '>=', $local->startOfYear()->toDateString())->count();

        return $this->enqueue($user, 'recruiting:'.$local->format('Y-m'), 'recruiting', ['moment' => 'recruiting', 'headline' => 'Grow your career with '.$this->tenants->current()->name,
            'body' => ($closed > 0 ? $closed.' homes closed together this year. ' : '').'Great tools, real support, and a team that wins together. Let\'s talk.']);
    }

    /** Learns the agent's voice from their own recent Facebook Page posts (with their OK), once a week. */
    private function learnVoice(User $user): void
    {
        $r = DB::connection(Connections::cell())->table('studio_autopilot')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->first();
        if ($r === null || ($r->voice_at !== null && CarbonImmutable::parse((string) $r->voice_at)->gt(CarbonImmutable::now()->subDays(7)))) {
            return;
        }
        $samples = [];
        foreach (app(ConnectorKit::class)->connections('facebook_pages') as $c) {
            if ($c->ownerUserId !== $user->id) {
                continue;
            }
            $tokens = json_decode((string) ($c->credentials['pages'] ?? '{}'), true);
            foreach (is_array($tokens) ? $tokens : [] as $page => $token) {
                $res = Http::timeout(15)->get(MetaApi::GRAPH.$page.'/posts', ['fields' => 'message', 'limit' => 15, 'access_token' => (string) $token]);
                foreach ((array) ($res->successful() ? $res->json('data', []) : []) as $p) {
                    $m = is_array($p) ? trim((string) ($p['message'] ?? '')) : '';
                    if (mb_strlen($m) >= 40) {
                        $samples[] = mb_substr($m, 0, 400);
                    }
                }
            }
        }
        DB::connection(Connections::cell())->table('studio_autopilot')->where('id', $r->id)->update(['voice' => json_encode(array_slice($samples, 0, 5)), 'voice_at' => now()]);
    }

    /**
     * Plans one post for a moment, once (by its source key), and starts its designs.
     *
     * @param  array<string, mixed>  $design  what the designer needs
     */
    private function enqueue(User $user, string $key, string $moment, array $design, ?CarbonImmutable $at = null): int
    {
        if (QueuedPost::query()->where('user_id', $user->id)->where('source_key', $key)->exists()) {
            return 0;
        }
        // A new listing's posts wait while its best photos are enhanced (SA5a), so they launch with the enhanced versions.
        $wait = isset($design['listing']) && app(StudioPhotos::class)->pending((string) $design['listing']);
        $item = QueuedPost::query()->create(['user_id' => $user->id, 'source_key' => $key, 'moment' => $moment, 'listing_ulid' => $design['listing'] ?? null,
            'status' => $wait ? 'enhancing' : 'designing']);
        if ($at !== null) {
            $item->forceFill(['post_at' => $at])->save();
        }
        if (! $wait) {
            $this->design($item, $user, $design);
        }

        return 1;
    }

    /**
     * A new listing's Reel (SA3b): made from its launch design when the person allows it and the package has Reels
     * left (credits only when the person allows extra posts), queued a day after the listing post.
     *
     * @param  array{auto_reels: bool, extra_credits: bool, times: list<string>, per_week: int}  $s
     */
    private function launchReel(QueuedPost $item, Design $design, User $user, array $s): void
    {
        $key = 'reel:'.$item->listing_ulid;
        if (! $s['auto_reels'] || (! $s['extra_credits'] && $this->plans->left($user, 'reel') < 1) || QueuedPost::query()->where('user_id', $user->id)->where('source_key', $key)->exists()) {
            return;
        }
        try {
            $video = app(Reels::class)->make($user, $design);
        } catch (ValidationException) {
            return;
        }
        $reel = QueuedPost::query()->create(['user_id' => $user->id, 'source_key' => $key, 'moment' => $item->moment, 'listing_ulid' => $item->listing_ulid, 'status' => 'filming']);
        $reel->forceFill(['design_id' => $design->id, 'video_id' => $video->id, 'post_at' => $this->slot($user, $s, ($item->post_at ?? CarbonImmutable::now())->addDay())])->save();
    }

    /**
     * What a queued post is designed from (for one that waited on photo enhancement).
     *
     * @return array<string, mixed>
     */
    private static function input(QueuedPost $item): array
    {
        $from = match (true) {
            str_ends_with($item->source_key, ':inside') => 3,
            str_ends_with($item->source_key, ':area') => 1,
            default => 0,
        };

        return array_filter(['moment' => $item->moment, 'listing' => $item->listing_ulid, 'photo_from' => $from], fn ($v): bool => $v !== null && $v !== 0);
    }

    /** @param array<string, mixed> $design */
    private function design(QueuedPost $item, User $user, array $design): void
    {
        // Rotate through the person's favourite styles, so the feed never looks the same twice in a row.
        $favorites = app(BrandKits::class)->forUser($user)['favorites'];
        if ($favorites !== [] && ! isset($design['style'])) {
            $design['style'] = $favorites[QueuedPost::query()->where('user_id', $user->id)->count() % count($favorites)];
        }
        try {
            $made = $this->designer->create($user, $design);
            $item->forceFill(['batch' => $made[0]->batch])->save();
        } catch (ValidationException $e) {
            $item->forceFill(['status' => 'failed', 'reason' => mb_substr((string) collect($e->errors())->flatten()->first(), 0, 300)])->save();
        }
    }

    private function caption(QueuedPost $item, Design $design, User $user): void
    {
        $kit = app(BrandKits::class)->forUser($user);
        $spanish = in_array('es', $kit['languages'], true) && $this->plans->has($user, 'spanish');
        $facts = ['moment' => Designer::MOMENTS[$item->moment][0] ?? $item->moment] + array_intersect_key($design->content, array_flip(['headline', 'sub', 'facts', 'price', 'stats', 'body']));
        if ($item->listing_ulid !== null) {
            $remarks = Listing::query()->where('ulid', $item->listing_ulid)->value('public_remarks');
            $facts['remarks'] = is_string($remarks) ? mb_substr(strip_tags($remarks), 0, 1200) : null;
        }
        $caption = '';
        $es = '';
        $tags = [];
        try {
            $r = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'ads.studio_caption', 'studio-caption:'.$item->ulid,
                inputs: ['spanish' => $spanish ? 'yes' : 'no', 'tagline' => (string) ($kit['details']['tagline'] ?? '')],
                untrustedContent: array_values(array_filter([(string) json_encode(array_filter($facts)),
                    ($voice = $this->settings($user)['voice']) !== [] && $this->plans->has($user, 'voice_learning') ? 'Voice samples (the agent\'s own past posts): '.json_encode($voice) : null])),
                outputSchema: ['type' => 'object', 'properties' => ['caption' => ['type' => 'string'], 'caption_es' => ['type' => 'string'], 'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']]]],
                userId: $user->id));
            $caption = mb_substr(trim((string) ($r->json['caption'] ?? '')), 0, 900);
            $es = $spanish ? mb_substr(trim((string) ($r->json['caption_es'] ?? '')), 0, 900) : '';
            $tags = array_slice(array_values(array_unique(array_filter(array_map(fn ($t): string => (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $t),
                (array) ($r->json['hashtags'] ?? [])), fn (string $t): bool => strlen($t) >= 2 && strlen($t) <= 40))), 0, 8);
        } catch (Throwable $e) {
            report($e);
        }
        if ($caption === '' || FairHousing::screen($caption.' '.$es.' '.implode(' ', $tags), 'ads', 'removed', 'Ally Studio caption') !== []) {
            [$caption, $es, $tags] = [$this->designer->caption($design), '', []]; // the plain caption, never wording that fails the check
        }
        $item->forceFill(['caption' => $caption, 'caption_es' => $es !== '' ? $es : null, 'hashtags' => $tags])->save();
    }

    /** Schedules the post on every chosen place, counting it against the allowance. */
    private function schedule(QueuedPost $item, User $user, bool $loud = false, bool $reviewed = false): QueuedPost
    {
        if (! $reviewed && $user->kind === UserKind::Agent && $this->rules()['require_review']) {
            $item->forceFill(['status' => 'review', 'reason' => null])->save(); // the brokerage looks first

            return $item;
        }
        $design = Design::query()->find($item->design_id);
        $s = $this->settings($user);
        $places = array_column(app(SocialPosts::class)->places($user), 'key');
        $places = $s['places'] !== null ? array_values(array_intersect($places, $s['places'])) : $places;
        try {
            $video = $item->video_id !== null ? StudioVideo::query()->find($item->video_id) : null;
            if ($video !== null) {
                $this->scheduleReel($item, $user, $video, $places, $s);

                return $item;
            }
            if ($design !== null && $design->used_at === null && ! $s['extra_credits'] && $this->plans->left($user, 'post') < 1) {
                // Autopilot stays inside the package: no surprise credit spend unless the person allows it.
                throw ValidationException::withMessages(['places' => 'This month\'s designed posts in your Studio package are used. Studio posts again when the package '
                    .'renews, or turn on "Use credits for extra posts" in Autopilot settings.']);
            }
            if ($design === null || $places === []) {
                throw ValidationException::withMessages(['places' => $design === null ? 'The design is missing.' : 'Connect where to post (Facebook Pages and Instagram, LinkedIn or Google) first.']);
            }
            $this->designer->use($design, $user);
            $at = $item->post_at !== null && $item->post_at->isFuture() ? $item->post_at->setTimezone($this->tz())->format('Y-m-d\TH:i') : '';
            $carousel = $this->designer->carousel($design);
            $post = app(SocialPosts::class)->schedule($user, ['text' => $this->text($item), 'image_url' => $this->designer->url($design, 'portrait'),
                'images' => count($carousel) >= 2 ? $carousel : [],
                'listing' => $item->listing_ulid, 'places' => $places, 'post_at' => $at]);
            $item->forceFill(['status' => 'scheduled', 'social_post_id' => $post->id, 'reason' => null])->save();
        } catch (ValidationException $e) {
            if ($loud) {
                throw $e;
            }
            $item->forceFill(['reason' => mb_substr((string) collect($e->errors())->flatten()->first(), 0, 300)])->save(); // stays waiting, with why
        }

        return $item;
    }

    /**
     * Schedules a Reel: the video where it plays (Instagram, Facebook, YouTube), the cover elsewhere; counted as a Reel.
     *
     * @param  list<string>  $places
     * @param  array{extra_credits: bool}  $s
     */
    private function scheduleReel(QueuedPost $item, User $user, StudioVideo $video, array $places, array $s): void
    {
        if ($video->status !== 'ready') {
            throw ValidationException::withMessages(['places' => 'The Reel is still being made.']);
        }
        if ($video->used_at === null && ! $s['extra_credits'] && $this->plans->left($user, 'reel') < 1) {
            throw ValidationException::withMessages(['places' => 'This month\'s Reels in your Studio package are used. Studio posts again when the package renews, '
                .'or turn on "Use credits for extra posts" in Autopilot settings.']);
        }
        if ($places === []) {
            throw ValidationException::withMessages(['places' => 'Connect where to post (Facebook Pages and Instagram, YouTube, LinkedIn or Google) first.']);
        }
        $reels = app(Reels::class);
        $reels->use($video, $user);
        $at = $item->post_at !== null && $item->post_at->isFuture() ? $item->post_at->setTimezone($this->tz())->format('Y-m-d\TH:i') : '';
        $post = app(SocialPosts::class)->schedule($user, ['text' => $this->text($item), 'image_url' => $reels->poster($video), 'video_url' => $reels->url($video),
            'listing' => $item->listing_ulid, 'places' => $places, 'post_at' => $at]);
        $item->forceFill(['status' => 'scheduled', 'social_post_id' => $post->id, 'reason' => null])->save();
    }

    /**
     * The next good time: the person's times of day, at most one post a day and their posts per week.
     *
     * @param  array{times: list<string>, per_week: int}  $s
     */
    private function slot(User $user, array $s, ?CarbonImmutable $notBefore = null): CarbonImmutable
    {
        $tz = $this->tz();
        $now = CarbonImmutable::now($tz);
        if ($notBefore !== null && $notBefore->gt($now)) {
            $now = $notBefore->setTimezone($tz)->startOfDay();
        }
        $taken = QueuedPost::query()->where('user_id', $user->id)->whereIn('status', ['enhancing', 'designing', 'filming', 'awaiting', 'review', 'scheduled'])->whereNotNull('post_at')->pluck('post_at')
            ->map(fn ($d): CarbonImmutable => CarbonImmutable::parse($d)->setTimezone($tz));
        for ($i = 0; $i < 21; $i++) {
            $day = $now->startOfDay()->addDays($i);
            if ($taken->contains(fn (CarbonImmutable $d): bool => $d->isSameDay($day))
                || $taken->filter(fn (CarbonImmutable $d): bool => $d->isoWeek() === $day->isoWeek() && $d->isoWeekYear() === $day->isoWeekYear())->count() >= $s['per_week']) {
                continue;
            }
            foreach ($s['times'] as $t) {
                [$h, $m] = array_map('intval', explode(':', $t));
                $at = $day->setTime($h, $m);
                if ($at->gt($now->addHour())) {
                    return $at->utc();
                }
            }
        }

        return $now->addDays(21)->setTime(9, 30)->utc();
    }

    private function tz(): string
    {
        return $this->tenants->current()->timezone ?: 'America/New_York';
    }

    private static function short(int $cents): string
    {
        $d = $cents / 100;

        return $d >= 1_000_000 ? rtrim(rtrim(number_format($d / 1_000_000, 2), '0'), '.').'M' : number_format((int) round($d / 1000)).'K';
    }

    /**
     * US holidays Studio greets (secular, so every brokerage can use them).
     *
     * @return array<string, array{0: string, 1: string, 2: string}> date => [key, headline, words]
     */
    public static function holidays(int $year): array
    {
        $first = fn (int $month): CarbonImmutable => CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month));
        $nth = fn (int $month, int $dow, int $n): string => $first($month)->nthOfMonth($n, $dow)->toDateString();
        $last = fn (int $month, int $dow): string => $first($month)->lastOfMonth($dow)->toDateString();

        return [
            "{$year}-01-01" => ['new_year', 'Happy New Year', 'Wishing you a bright year ahead, and a home you love.'],
            $last(5, CarbonImmutable::MONDAY) => ['memorial_day', 'Memorial Day', 'Today we remember and honour those who gave everything.'],
            "{$year}-07-04" => ['independence_day', 'Happy Fourth of July', 'Wishing you a safe and happy Independence Day.'],
            $nth(9, CarbonImmutable::MONDAY, 1) => ['labor_day', 'Happy Labor Day', 'Thank you to everyone who builds and keeps our communities going.'],
            $nth(11, CarbonImmutable::THURSDAY, 4) => ['thanksgiving', 'Happy Thanksgiving', 'Grateful for every client, neighbour and friend this year.'],
            "{$year}-12-23" => ['happy_holidays', 'Happy Holidays', 'Warm wishes to you and yours this season.'],
        ];
    }
}
