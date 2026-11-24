<?php

declare(strict_types=1);

namespace Modules\Compensation\Ranks;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\RevenueShare\Unlocking;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * CM1: ranks. An agent earns a rank by production (closed deals or GCI in a window) and by recruits (active
 * agents they brought in who closed a deal in the same window). A rank can open revenue-share levels (on top of
 * the plan's own unlocking) and can name a better commission plan; moving someone to that plan is always the
 * broker's decision, never automatic.
 *
 * @phpstan-type Rank array{ulid: string, name: string, position: int, min_closings: int, min_gci_cents: int, min_recruits: int, window_months: int, unlock_levels: int|null, plan_id: int|null}
 */
final class Ranks
{
    public const MAX = 8;

    /** @var array<int, list<Rank>> per brokerage */
    private array $cache = [];

    /** @var array<string, int> */
    private array $recruitCache = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The brokerage's ranks, lowest first.
     *
     * @return list<Rank>
     */
    public function all(): array
    {
        return $this->cache[(int) $this->tenants->id()] ??= array_values(DB::connection(Connections::cell())->table('comp_ranks')->orderBy('position')->get()
            ->map(fn (object $r): array => ['ulid' => (string) $r->ulid, 'name' => (string) $r->name, 'position' => (int) $r->position,
                'min_closings' => (int) $r->min_closings, 'min_gci_cents' => (int) $r->min_gci_cents, 'min_recruits' => (int) $r->min_recruits,
                'window_months' => (int) $r->window_months, 'unlock_levels' => $r->unlock_levels !== null ? (int) $r->unlock_levels : null,
                'plan_id' => $r->plan_id !== null ? (int) $r->plan_id : null])->all());
    }

    /**
     * A starting set of ranks for a brokerage to adjust (the broker decides the real numbers).
     *
     * @return list<array{name: string, min_closings: int, min_gci_cents: int, min_recruits: int, window_months: int, unlock_levels: int|null}>
     */
    public static function suggested(): array
    {
        return [
            ['name' => 'Associate', 'min_closings' => 0, 'min_gci_cents' => 0, 'min_recruits' => 0, 'window_months' => 12, 'unlock_levels' => 1],
            ['name' => 'Producer', 'min_closings' => 6, 'min_gci_cents' => 5_000_000, 'min_recruits' => 0, 'window_months' => 12, 'unlock_levels' => 2],
            ['name' => 'Team leader', 'min_closings' => 12, 'min_gci_cents' => 10_000_000, 'min_recruits' => 2, 'window_months' => 12, 'unlock_levels' => 4],
            ['name' => 'Partner', 'min_closings' => 24, 'min_gci_cents' => 20_000_000, 'min_recruits' => 5, 'window_months' => 12, 'unlock_levels' => 7],
        ];
    }

    /**
     * Replaces the brokerage's ranks (lowest first). Each rank must ask at least as much as the one below it.
     *
     * @param  list<array<string, mixed>>  $ranks
     */
    public function save(array $ranks, User $by): void
    {
        if (count($ranks) > self::MAX) {
            throw ValidationException::withMessages(['ranks' => 'Up to '.self::MAX.' ranks.']);
        }
        $rows = [];
        $previous = null;
        foreach ($ranks as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $row = [
                'name' => mb_substr($name, 0, 60),
                'min_closings' => max(0, (int) ($r['min_closings'] ?? 0)),
                'min_gci_cents' => max(0, (int) ($r['min_gci_cents'] ?? 0)),
                'min_recruits' => max(0, (int) ($r['min_recruits'] ?? 0)),
                'window_months' => min(36, max(1, (int) ($r['window_months'] ?? 12))),
                'unlock_levels' => isset($r['unlock_levels']) && $r['unlock_levels'] !== '' ? min(15, max(0, (int) $r['unlock_levels'])) : null,
                'plan_id' => null,
            ];
            if ($name === '') {
                throw ValidationException::withMessages(["ranks.{$i}.name" => 'Give each rank a name.']);
            }
            if (isset($r['plan']) && is_string($r['plan']) && $r['plan'] !== '') {
                $plan = Plan::query()->where('ulid', $r['plan'])->where('status', 'active')->first();
                if ($plan === null) {
                    throw ValidationException::withMessages(["ranks.{$i}.plan" => 'Choose an active commission plan.']);
                }
                $row['plan_id'] = $plan->id;
            }
            if ($previous !== null && ($row['min_closings'] < $previous['min_closings'] || $row['min_gci_cents'] < $previous['min_gci_cents'] || $row['min_recruits'] < $previous['min_recruits'])) {
                throw ValidationException::withMessages(["ranks.{$i}" => "{$name} must ask at least as much as {$previous['name']}, the rank below it."]);
            }
            $rows[] = $row;
            $previous = $row;
        }

        $db = DB::connection(Connections::cell());
        $db->transaction(function () use ($db, $rows, $by): void {
            $db->table('comp_ranks')->delete();
            foreach ($rows as $i => $row) {
                $db->table('comp_ranks')->insert($row + ['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'position' => $i + 1,
                    'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->cache = [];
        app(AuditLogger::class)->record('compensation.ranks.saved', metadata: ['ranks' => array_column($rows, 'name')], severity: 'warning');
    }

    /**
     * Where an agent stands: their rank, the next one and what is still needed for it.
     *
     * @return array{rank: Rank|null, next: Rank|null, closings: int, gci_cents: int, recruits: int, needs: list<string>}
     */
    public function progress(int $userId, CarbonImmutable $on, ?Unlocking $unlocking = null): array
    {
        $unlocking ??= app(Unlocking::class);
        $rank = null;
        $next = null;
        $measured = ['closings' => 0, 'gci_cents' => 0, 'recruits' => 0];
        foreach ($this->all() as $r) {
            $m = $this->measure($userId, $on, $r['window_months'], $unlocking);
            if ($this->meets($r, $m)) {
                $rank = $r;
                $measured = $m;

                continue;
            }
            $next = $r;
            break;
        }
        $now = $next !== null ? $this->measure($userId, $on, $next['window_months'], $unlocking) : $measured;

        return ['rank' => $rank, 'next' => $next, 'closings' => $now['closings'], 'gci_cents' => $now['gci_cents'], 'recruits' => $now['recruits'],
            'needs' => $next !== null ? self::needs($next, $now) : []];
    }

    /** The revenue-share levels an agent's rank opens on a date (null when the brokerage has no ranks). */
    public function unlockedLevels(int $userId, CarbonImmutable $on, ?Unlocking $unlocking = null): ?int
    {
        if ($this->all() === []) {
            return null;
        }
        $rank = $this->progress($userId, $on, $unlocking)['rank'];

        return $rank['unlock_levels'] ?? null;
    }

    public function forget(): void
    {
        $this->cache = [];
        $this->recruitCache = [];
    }

    /**
     * @return array{closings: int, gci_cents: int, recruits: int}
     */
    private function measure(int $userId, CarbonImmutable $on, int $months, Unlocking $unlocking): array
    {
        $from = $on->subMonthsNoOverflow($months);
        $p = $unlocking->production($userId, $from, $on);
        $key = "{$this->tenants->id()}:{$userId}:{$from->toDateString()}:{$on->toDateString()}";
        if (! isset($this->recruitCache[$key])) {
            $front = $unlocking->frontLine($userId, $on);
            $active = $front === [] ? [] : User::query()->whereKey($front)->where('status', UserStatus::Active)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $this->recruitCache[$key] = count(array_filter($active, fn (int $id): bool => $unlocking->production($id, $from, $on)['closings'] > 0));
        }

        return ['closings' => $p['closings'], 'gci_cents' => $p['gci'], 'recruits' => $this->recruitCache[$key]];
    }

    /**
     * Production is met by enough closed deals or enough GCI (either one); recruits must be met as well.
     *
     * @param  Rank  $r
     * @param  array{closings: int, gci_cents: int, recruits: int}  $m
     */
    private function meets(array $r, array $m): bool
    {
        return self::producing($r, $m) && $m['recruits'] >= $r['min_recruits'];
    }

    /**
     * @param  Rank  $r
     * @param  array{closings: int, gci_cents: int, recruits: int}  $m
     */
    private static function producing(array $r, array $m): bool
    {
        return ($r['min_closings'] === 0 && $r['min_gci_cents'] === 0)
            || ($r['min_closings'] > 0 && $m['closings'] >= $r['min_closings'])
            || ($r['min_gci_cents'] > 0 && $m['gci_cents'] >= $r['min_gci_cents']);
    }

    /**
     * What is still needed for a rank, in plain words.
     *
     * @param  Rank  $r
     * @param  array{closings: int, gci_cents: int, recruits: int}  $m
     * @return list<string>
     */
    private static function needs(array $r, array $m): array
    {
        $needs = [];
        if (! self::producing($r, $m)) {
            $parts = [];
            if ($r['min_closings'] > 0) {
                $more = $r['min_closings'] - $m['closings'];
                $parts[] = $more.' more closed '.($more === 1 ? 'deal' : 'deals');
            }
            if ($r['min_gci_cents'] > 0) {
                $parts[] = '$'.number_format(($r['min_gci_cents'] - $m['gci_cents']) / 100).' more GCI';
            }
            $needs[] = implode(' or ', $parts);
        }
        if ($m['recruits'] < $r['min_recruits']) {
            $more = $r['min_recruits'] - $m['recruits'];
            $needs[] = $more.' more '.($more === 1 ? 'recruit' : 'recruits').' who closed a deal';
        }

        return $needs;
    }
}
