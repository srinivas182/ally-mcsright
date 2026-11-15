<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ads\ListingAds;
use Modules\Ads\Social\SocialPosts;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Models\Listing;
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

    public const KINDS = ['listings' => 'Listing news (new, price, under contract, sold)', 'market' => 'Weekly market update (Mondays)', 'holidays' => 'Holiday greetings'];

    public const DEFAULT_TIMES = ['09:30', '18:00'];

    public function __construct(private readonly TenantContext $tenants, private readonly Designer $designer, private readonly StudioPlans $plans) {}

    /** @return array{mode: string, places: list<string>|null, times: list<string>, per_week: int, moments: list<string>} */
    public function settings(User $user): array
    {
        $r = DB::connection(Connections::cell())->table('studio_autopilot')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->first();
        $json = fn (mixed $v): ?array => is_string($v) && is_array($d = json_decode($v, true)) ? array_values(array_map('strval', $d)) : null;

        return ['mode' => (string) ($r->mode ?? 'approve'), 'places' => $json($r->places ?? null), 'times' => $json($r->times ?? null) ?? self::DEFAULT_TIMES,
            'per_week' => (int) ($r->per_week ?? 5), 'moments' => $json($r->moments ?? null) ?? array_keys(self::KINDS)];
    }

    /** @param array<string, mixed> $in mode, places[], times[], per_week, moments[] */
    public function save(User $user, array $in): void
    {
        $times = array_values(array_unique(array_filter(array_map('strval', (array) ($in['times'] ?? [])), fn (string $t): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1)));
        $places = array_key_exists('places', $in) && $in['places'] !== null ? array_values(array_intersect(array_map('strval', (array) $in['places']),
            array_column(app(SocialPosts::class)->places($user), 'key'))) : null;
        $values = ['mode' => isset(self::MODES[$in['mode'] ?? '']) ? (string) $in['mode'] : 'approve', 'places' => $places !== null ? json_encode($places) : null,
            'times' => json_encode($times !== [] ? array_slice($times, 0, 4) : self::DEFAULT_TIMES), 'per_week' => max(1, min(14, (int) ($in['per_week'] ?? 5))),
            'moments' => json_encode(array_values(array_intersect(array_keys(self::KINDS), array_map('strval', (array) ($in['moments'] ?? []))))), 'updated_at' => now()];
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
        $this->advance($user);

        return $new;
    }

    /** Moves designed posts on: caption and time, then waiting for approval or scheduled. */
    public function advance(User $user): void
    {
        $s = $this->settings($user);
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

    public function skip(QueuedPost $item, User $by): void
    {
        abort_unless($item->user_id === $by->id, 404);
        if (in_array($item->status, ['designing', 'awaiting'], true)) {
            $item->forceFill(['status' => 'skipped'])->save();
        }
    }

    /** The words that go out: caption, Spanish, hashtags. */
    public function text(QueuedPost $item): string
    {
        $tags = array_map(fn (string $t): string => '#'.$t, $item->hashtags ?? []);

        return trim(implode("\n\n", array_filter([(string) $item->caption, (string) $item->caption_es, implode(' ', $tags)])));
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
                $new += $this->enqueue($user, $key, $moment, ['moment' => $moment, 'listing' => $l->ulid]);
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
        $item = QueuedPost::query()->create(['user_id' => $user->id, 'source_key' => $key, 'moment' => $moment, 'listing_ulid' => $design['listing'] ?? null, 'status' => 'designing']);
        if ($at !== null) {
            $item->forceFill(['post_at' => $at])->save();
        }
        try {
            $made = $this->designer->create($user, $design);
            $item->forceFill(['batch' => $made[0]->batch])->save();
        } catch (ValidationException $e) {
            $item->forceFill(['status' => 'failed', 'reason' => mb_substr((string) collect($e->errors())->flatten()->first(), 0, 300)])->save();
        }

        return 1;
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
                untrustedContent: [(string) json_encode(array_filter($facts))],
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
    private function schedule(QueuedPost $item, User $user, bool $loud = false): QueuedPost
    {
        $design = Design::query()->find($item->design_id);
        $s = $this->settings($user);
        $places = array_column(app(SocialPosts::class)->places($user), 'key');
        $places = $s['places'] !== null ? array_values(array_intersect($places, $s['places'])) : $places;
        try {
            if ($design === null || $places === []) {
                throw ValidationException::withMessages(['places' => $design === null ? 'The design is missing.' : 'Connect where to post (Facebook Pages and Instagram, LinkedIn or Google) first.']);
            }
            $this->designer->use($design, $user);
            $at = $item->post_at !== null && $item->post_at->isFuture() ? $item->post_at->setTimezone($this->tz())->format('Y-m-d\TH:i') : '';
            $post = app(SocialPosts::class)->schedule($user, ['text' => $this->text($item), 'image_url' => $this->designer->url($design, 'portrait'),
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
     * The next good time: the person's times of day, at most one post a day and their posts per week.
     *
     * @param  array{times: list<string>, per_week: int}  $s
     */
    private function slot(User $user, array $s): CarbonImmutable
    {
        $tz = $this->tz();
        $now = CarbonImmutable::now($tz);
        $taken = QueuedPost::query()->where('user_id', $user->id)->whereIn('status', ['awaiting', 'scheduled'])->whereNotNull('post_at')->pluck('post_at')
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
