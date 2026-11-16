<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ads\Social\SocialMetrics;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialPosts;
use Modules\Ads\Social\SocialTarget;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Lead;
use Throwable;

/**
 * Ally Studio results (specs/grow/13, SA4): what each post did — reach, views, likes, comments, shares, saves and
 * clicks from the networks that share them (Facebook and Instagram; LinkedIn, Google and YouTube don't on the
 * permissions Studio asks for), and the leads that arrived through its link — by format, style and time of day.
 * Autopilot learns from it: once there are enough measured posts, it leads with the style and the times that do best.
 */
final class StudioResults
{
    /** Posts are re-read daily for this many days after they go out. */
    public const DAYS = 30;

    /** Autopilot changes nothing until this many of the person's posts have numbers. */
    public const LEARN_AFTER = 8;

    public function __construct(private readonly ConnectorKit $kit, private readonly TenantContext $tenants) {}

    /** Daily: reads the numbers for the tenant's recent posts. Returns how many places were read. */
    public function refresh(): int
    {
        $read = 0;
        $since = CarbonImmutable::now()->subDays(self::DAYS);
        $targets = SocialTarget::query()->where('status', 'posted')->whereNotNull('external_id')->where('done_at', '>=', $since)->orderBy('id')->limit(500)->get();
        $fresh = DB::connection(Connections::cell())->table('ads_social_metrics')->where('tenant_id', $this->tenants->id())
            ->whereIn('target_id', $targets->pluck('id'))->where('fetched_at', '>', CarbonImmutable::now()->subHours(20))->pluck('target_id')->all();
        foreach ($targets as $t) {
            if (in_array($t->id, $fresh, true)) {
                continue;
            }
            $c = $this->kit->connection($t->connection_id);
            $driver = $c !== null ? (SocialPosts::APPS[$c->appKey] ?? null) : null;
            $reader = $driver !== null ? app($driver) : null;
            if ($c === null || ! $reader instanceof SocialMetrics) {
                continue;
            }
            try {
                $m = $reader->metrics($c, $t->channel, $t->target_id, (string) $t->external_id);
                $error = $m === null ? 'The network did not share numbers for this post.' : null;
            } catch (Throwable $e) {
                $m = null;
                $error = mb_substr($e->getMessage(), 0, 200);
            }
            DB::connection(Connections::cell())->table('ads_social_metrics')->updateOrInsert(['target_id' => $t->id],
                ($m ?? []) + ['tenant_id' => $this->tenants->id(), 'fetched_at' => now(), 'error' => $error, 'updated_at' => now(), 'created_at' => now()]);
            $read++;
        }

        return $read;
    }

    /**
     * One person's month (or a brokerage's, for team reports): totals, by format, style and time of day, and the top posts.
     *
     * @param  list<int>|null  $users  null: just this person
     * @return array<string, mixed>
     */
    public function month(User $user, string $month, ?array $users = null): array
    {
        $start = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $posts = $this->posts($users ?? [$user->id], $start, $start->endOfMonth());
        $sum = fn (Collection $rows): array => [
            'posts' => $rows->count(),
            'reach' => (int) $rows->sum('reach'),
            'interactions' => (int) $rows->sum('interactions'),
            'clicks' => (int) $rows->sum('clicks'),
            'leads' => (int) $rows->sum('leads'),
            'rate' => $rows->sum('reach') > 0 ? round($rows->sum('interactions') * 100 / $rows->sum('reach'), 1) : null,
        ];
        $by = fn (string $key): array => $posts->groupBy($key)->map(fn (Collection $rows, string $k): array => ['key' => $k] + $sum($rows))->sortByDesc('rate')->values()->all();

        return [
            'month' => $start->format('Y-m'), 'label' => $start->format('F Y'),
            'totals' => $sum($posts),
            'formats' => $by('format'),
            'styles' => $by('style'),
            'times' => $by('time'),
            'top' => $posts->sortByDesc(fn (array $p): int => $p['interactions'] * 10 + $p['reach'])->take(5)->map(fn (array $p): array => array_intersect_key($p,
                array_flip(['ulid', 'listing', 'facebook', 'text', 'image', 'format', 'style', 'reach', 'interactions', 'clicks', 'leads', 'url', 'posted'])))->values()->all(),
        ];
    }

    /**
     * What Autopilot has learned for this person: the style and the times of day that do best, once enough posts have numbers.
     *
     * @return array{style: string|null, times: list<string>, measured: int}
     */
    public function learned(User $user): array
    {
        $posts = $this->posts([$user->id], CarbonImmutable::now()->subDays(90), CarbonImmutable::now())->filter(fn (array $p): bool => $p['reach'] > 0);
        if ($posts->count() < self::LEARN_AFTER) {
            return ['style' => null, 'times' => [], 'measured' => $posts->count()];
        }
        $rate = fn (Collection $rows): float => $rows->sum('interactions') / max(1, $rows->sum('reach'));
        $styles = $posts->whereNotNull('style_key')->groupBy('style_key')->filter(fn (Collection $r): bool => $r->count() >= 2)->map($rate)->sortDesc();
        $times = $posts->groupBy('hhmm')->filter(fn (Collection $r): bool => $r->count() >= 2)->map($rate)->sortDesc();

        return ['style' => is_string($styles->keys()->first()) ? $styles->keys()->first() : null, 'times' => array_values(array_map('strval', $times->keys()->all())),
            'measured' => $posts->count()];
    }

    /**
     * Posted posts with their numbers, origin and leads.
     *
     * @param  list<int>  $users
     * @return Collection<int, array{ulid: string, listing: string|null, facebook: bool, text: string, image: string|null, format: string, style_key: string|null, style: string, time: string, hhmm: string, posted: string, reach: int, interactions: int, clicks: int, leads: int, url: string|null}>
     */
    private function posts(array $users, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $tz = $this->tenants->current()->timezone ?: 'America/New_York';
        $posts = SocialPost::query()->whereIn('user_id', $users)->whereIn('status', ['posted', 'partial'])->whereBetween('posted_at', [$from, $to])->with('targets')->get();
        if ($posts->isEmpty()) {
            return collect();
        }
        $metrics = DB::connection(Connections::cell())->table('ads_social_metrics')->where('tenant_id', $this->tenants->id())
            ->whereIn('target_id', $posts->flatMap(fn (SocialPost $p) => $p->targets->pluck('id')))->get()->keyBy('target_id');
        $tags = $posts->mapWithKeys(fn (SocialPost $p): array => ['rightally-'.strtolower($p->ulid) => $p->id]);
        $leads = Lead::query()->whereIn('utm->utm_campaign', $tags->keys()->all())->get(['utm', 'contact_id'])
            ->groupBy(fn (Lead $l): string => (string) ($l->utm['utm_campaign'] ?? ''))->map(fn (Collection $g): int => $g->pluck('contact_id')->filter()->unique()->count());
        $styles = Design::query()->whereIn('id', $posts->pluck('studio_design_id')->filter()->unique())->pluck('collection', 'id');

        return $posts->map(function (SocialPost $p) use ($metrics, $leads, $styles, $tz): array {
            $m = $p->targets->map(fn (SocialTarget $t) => $metrics[$t->id] ?? null)->filter();
            $interactions = (int) $m->sum(fn (object $r): int => (int) $r->likes + (int) $r->comments + (int) $r->shares + (int) $r->saves);
            $local = ($p->posted_at ?? $p->post_at)->setTimezone($tz);
            $styleKey = isset($styles[$p->studio_design_id]) ? Styles::key((string) $styles[$p->studio_design_id]) : null;

            return ['ulid' => $p->ulid, 'listing' => $p->listing_ulid, 'facebook' => $p->targets->contains(fn (SocialTarget $t): bool => $t->channel === 'facebook' && $t->status === 'posted'),
                'text' => mb_substr($p->text, 0, 140), 'image' => $p->image_url, 'format' => $p->format ?? 'photo',
                'style_key' => $styleKey, 'style' => $styleKey !== null ? (string) Styles::get($styleKey)['name'] : 'Not from Studio',
                'time' => match (true) {
                    $local->hour < 12 => 'Morning',
                    $local->hour < 17 => 'Afternoon',
                    default => 'Evening',
                },
                'hhmm' => $local->format('H:i'), 'posted' => $local->format('M j, g:i A'),
                'reach' => (int) $m->sum(fn (object $r): int => max((int) $r->reach, (int) $r->views)), 'interactions' => $interactions, 'clicks' => (int) $m->sum('clicks'),
                'leads' => (int) ($leads['rightally-'.strtolower($p->ulid)] ?? 0), 'url' => $p->targets->firstWhere('status', 'posted')?->url];
        })->values();
    }
}
