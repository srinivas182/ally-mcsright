<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\Economics;
use Modules\Billing\Credits\RateCard;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\AppAddon;
use Modules\Connect\PaidApps;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Ally Studio packages (specs/grow/13, SA1a): three monthly add-ons (paid apps, specs/connect/14), each with HQ's
 * monthly allowances. The brokerage package covers every person of the brokerage; an agent's own package covers
 * them; the better one applies. Past the allowance, each item is paid with credits at the rate card price.
 *
 * A Studio package only counts once HQ has priced it: an unpriced Studio add-on is not on sale, never free.
 */
final class StudioPlans
{
    /** Package app keys, best last. */
    public const PACKAGES = ['studio_essentials' => 1, 'studio_luxury' => 2, 'studio_brokerage' => 3];

    public const ITEMS = ['post' => 'Designed posts', 'reel' => 'Reels and Shorts', 'tour' => 'YouTube listing tours', 'enhance' => 'Photo enhancements', 'voice' => 'Voice narrations'];

    public const FEATURES = ['launch_campaign' => 'Listing launch campaigns', 'spanish' => 'Spanish captions', 'voice_learning' => 'Captions in your own voice',
        'recruiting' => 'Recruiting posts', 'approval_rules' => 'Brokerage approval rules', 'team_report' => 'Team reporting'];

    public function __construct(private readonly PaidApps $paid, private readonly AppRegistry $registry, private readonly TenantContext $tenants) {}

    /**
     * HQ's allowances and features per package.
     *
     * @return array<string, array{posts: int, reels: int, tours: int, enhance: int, voice: int, features: list<string>}>
     */
    public function packages(): array
    {
        $out = [];
        foreach (DB::connection(Connections::control())->table('studio_packages')->get() as $r) {
            $features = json_decode((string) $r->features, true);
            $out[(string) $r->app_key] = ['posts' => (int) $r->posts, 'reels' => (int) $r->reels, 'tours' => (int) $r->tours, 'enhance' => (int) $r->enhance,
                'voice' => (int) $r->voice, 'features' => is_array($features) ? array_values(array_map('strval', $features)) : []];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in  posts, reels, tours, enhance, voice, features
     */
    public function savePackage(string $appKey, array $in, User $by): void
    {
        abort_unless(isset(self::PACKAGES[$appKey]), 404);
        $n = fn (string $k): int => max(0, min(10_000, (int) ($in[$k] ?? 0)));
        $values = ['posts' => $n('posts'), 'reels' => $n('reels'), 'tours' => $n('tours'), 'enhance' => $n('enhance'), 'voice' => $n('voice'),
            'features' => json_encode(array_values(array_intersect(array_keys(self::FEATURES), array_map('strval', (array) ($in['features'] ?? []))))), 'updated_by' => $by->id,
            'updated_at' => now()];
        DB::connection(Connections::control())->table('studio_packages')->updateOrInsert(['app_key' => $appKey], $values + ['created_at' => now()]);
        app(AuditLogger::class)->record('studio.package_changed', metadata: ['package' => $appKey] + $values, severity: 'notice');
    }

    /**
     * This person's Studio package now: which one, its allowances, and the month it counts from.
     *
     * @return array{package: string, name: string, allowances: array{post: int, reel: int, tour: int, enhance: int, voice: int}, features: list<string>, period: string, renews: string|null}|null
     */
    public function current(User $user): ?array
    {
        if (! in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true)) {
            return null;
        }
        $best = null;
        foreach (self::PACKAGES as $key => $rank) {
            $app = app(AppCatalog::class)->find($key);
            if ($app === null || $this->registry->price($app) === null || ! $this->registry->ready($app)) {
                continue; // not on sale yet
            }
            $owner = $key === 'studio_brokerage' ? null : $user->id;
            if ($this->paid->allowed($app, $owner)) {
                $best = [$key, $app->name, $owner];
            }
        }
        if ($best === null) {
            return null;
        }
        [$key, $name, $owner] = $best;
        $p = $this->packages()[$key] ?? ['posts' => 0, 'reels' => 0, 'tours' => 0, 'enhance' => 0, 'voice' => 0, 'features' => []];
        $addon = AppAddon::query()->where('app_key', $key)->where(fn ($q) => $owner === null ? $q->whereNull('payer_user_id') : $q->where('payer_user_id', $owner))->first();
        // Months follow the add-on; a package that includes Studio counts calendar months.
        $start = $addon !== null && $addon->period_end->isFuture() ? $addon->period_start : CarbonImmutable::now()->startOfMonth();

        return ['package' => $key, 'name' => $name, 'allowances' => ['post' => $p['posts'], 'reel' => $p['reels'], 'tour' => $p['tours'], 'enhance' => $p['enhance'], 'voice' => $p['voice']],
            'features' => $p['features'], 'period' => $start->toDateString(), 'renews' => $addon?->period_end->toDateString()];
    }

    public function has(User $user, string $feature): bool
    {
        return in_array($feature, $this->current($user)['features'] ?? [], true);
    }

    /** @return array<string, array{used: int, extra: int, allowance: int}> this month's use */
    public function usage(User $user): array
    {
        $plan = $this->current($user);
        $rows = $plan === null ? collect() : DB::connection(Connections::cell())->table('studio_usage')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->where('period', $plan['period'])->get()->keyBy('item');
        $out = [];
        foreach (array_keys(self::ITEMS) as $item) {
            $out[$item] = ['used' => (int) ($rows[$item]->used ?? 0), 'extra' => (int) ($rows[$item]->extra ?? 0), 'allowance' => $plan['allowances'][$item] ?? 0];
        }

        return $out;
    }

    /**
     * Uses one item: from this month's allowance if any is left, otherwise paid with credits (once per $ref).
     * Returns how it was paid.
     *
     * @return 'allowance'|'credits'
     */
    public function use(User $user, string $item, string $ref): string
    {
        abort_unless(isset(self::ITEMS[$item]), 500);
        $plan = $this->current($user) ?? throw ValidationException::withMessages(['studio' => 'Ally Studio is an add-on. Choose a Studio package under Grow → Ally Studio first.']);
        $db = DB::connection(Connections::cell());
        $key = ['tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'period' => $plan['period'], 'item' => $item];
        $db->table('studio_usage')->insertOrIgnore($key + ['used' => 0, 'extra' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $taken = $db->table('studio_usage')->where($key)->where('used', '<', $plan['allowances'][$item])->update(['used' => DB::raw('used + 1'), 'updated_at' => now()]);
        if ($taken === 1) {
            return 'allowance';
        }
        try {
            app(CreditLedger::class)->charge('studio.'.$item, $user->id, 'studio:'.$item.':'.$ref);
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['studio' => 'This month\'s '.mb_strtolower(self::ITEMS[$item]).' are used up, and there aren\'t enough credits for more ('
                .app(RateCard::class)->rate('studio.'.$item)['credits'].' each). Buy credits or move to a bigger Studio package.']);
        }
        $db->table('studio_usage')->where($key)->update(['extra' => DB::raw('extra + 1'), 'updated_at' => now()]);

        return 'credits';
    }

    /** Gives back one item used for something that then failed (an allowance item; credits are refunded by the ledger's caller). */
    public function giveBack(User $user, string $item): void
    {
        $plan = $this->current($user);
        if ($plan !== null) {
            DB::connection(Connections::cell())->table('studio_usage')->where(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'period' => $plan['period'], 'item' => $item])
                ->where('used', '>', 0)->update(['used' => DB::raw('used - 1'), 'updated_at' => now()]);
        }
    }

    /**
     * HQ's view: for each package its price, what its full allowance costs RightAlly and the margin; for each item
     * past the allowance its credits, price, cost and margin.
     *
     * @return array{packages: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function economics(): array
    {
        $costs = app(Economics::class)->catalogCosts(); // micro-cents per item
        $value = app(RateCard::class)->creditValueMicrocents();
        $pct = fn (int $revenue, int $cost): ?int => $revenue > 0 ? (int) round(($revenue - $cost) * 100 / $revenue) : null;
        $packages = [];
        foreach ($this->packages() as $key => $p) {
            $app = app(AppCatalog::class)->find($key);
            $price = $app !== null ? $this->registry->price($app) : null;
            $cost = $p['posts'] * ($costs['studio.post'] ?? 0) + $p['reels'] * ($costs['studio.reel'] ?? 0) + $p['tours'] * ($costs['studio.tour'] ?? 0)
                + $p['enhance'] * ($costs['studio.enhance'] ?? 0) + $p['voice'] * ($costs['studio.voice'] ?? 0) + ($costs['studio.cutout'] ?? 0);
            $revenue = ($price['cents'] ?? 0) * 1_000_000;
            $packages[] = ['key' => $key, 'name' => $app->name ?? $key, 'price_cents' => $price['cents'] ?? null, 'allowances' => $p, 'cost_cents' => intdiv($cost, 1_000_000),
                'margin_pct' => $price !== null ? $pct($revenue, $cost) : null];
        }
        $items = [];
        foreach (self::ITEMS as $item => $label) {
            $credits = app(RateCard::class)->rate('studio.'.$item)['credits'];
            $revenue = $credits * $value;
            $cost = $costs['studio.'.$item] ?? 0;
            $items[] = ['item' => $item, 'label' => $label, 'credits' => $credits, 'price_cents' => intdiv($revenue, 1_000_000), 'cost_cents' => intdiv($cost, 1_000_000),
                'margin_pct' => $pct($revenue, $cost)];
        }

        return ['packages' => $packages, 'items' => $items];
    }
}
