<?php

declare(strict_types=1);

namespace Modules\Core\Switches;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use stdClass;

/**
 * Feature switches and seats (FL1, specs/core/16).
 *
 * Whether an area is on for a brokerage, in order:
 * 1. HQ's choice for that brokerage (on or off) wins.
 * 2. Otherwise HQ's choice for everyone: off, on, or on for a share of brokerages (a gradual roll-out; the same
 *    brokerages stay in as the share grows).
 * 3. Otherwise the area's default.
 * Then the brokerage may switch an area off for itself (never on when HQ has it off).
 *
 * Seats: when HQ sets a seat limit for an area in a brokerage, only owners and the people given a seat may use it.
 */
final class FeatureSwitches
{
    private const TTL = 300;

    /** @var array<int, array{rows: array<string, stdClass>, everyone: array<string, stdClass>, seats: array<string, int>}> */
    private array $memo = [];

    /** @var array<string, list<string>> tenant:user => areas held */
    private array $held = [];

    public function __construct(private readonly Areas $areas, private readonly TenantContext $tenants) {}

    public function on(string $area, ?int $tenantId = null): bool
    {
        $def = $this->areas->get($area);
        $tenantId ??= $this->tenants->idOrNull();
        if ($def === null || $tenantId === null) {
            return true; // unknown areas and pages outside a brokerage are never switched off here
        }
        $data = $this->load($tenantId);
        $own = $data['rows'][$area] ?? null;
        $all = $data['everyone'][$area] ?? null;
        if ($own !== null && $own->hq_state !== null) {
            $on = $own->hq_state === 'on';
        } elseif ($all !== null && $all->hq_state === 'off') {
            $on = false;
        } elseif ($all !== null && $all->rollout_percent !== null) {
            $on = self::bucket($tenantId, $area) < (int) $all->rollout_percent;
        } elseif ($all !== null && $all->hq_state === 'on') {
            $on = true;
        } else {
            $on = $def['default_on'];
        }

        return $on && ! ($own !== null && (bool) $own->brokerage_off);
    }

    /** Whether HQ allows the area for this brokerage (before the brokerage's own choice). */
    public function hqAllows(string $area, int $tenantId): bool
    {
        $own = $this->load($tenantId)['rows'][$area] ?? null;
        if ($own === null || ! (bool) $own->brokerage_off) {
            return $this->on($area, $tenantId);
        }
        $saved = $own->brokerage_off;
        $own->brokerage_off = false;
        $on = $this->on($area, $tenantId);
        $own->brokerage_off = $saved;

        return $on;
    }

    /** Whether this person may open the page: its area is on, and they hold a seat when the area is seat-limited. */
    public function allowsPath(User $user, string $path): bool
    {
        $area = $this->areas->forPath($path);

        return $area === null || $this->allows($user, $area);
    }

    public function allows(User $user, string $area): bool
    {
        if ($user->kind === UserKind::HqStaff || ! $this->tenants->check()) {
            return true;
        }
        if (! $this->on($area)) {
            return false;
        }

        return $this->seatLimit($area) === null || $user->kind === UserKind::Owner || $this->holdsSeat($user, $area);
    }

    public function seatLimit(string $area, ?int $tenantId = null): ?int
    {
        $tenantId ??= $this->tenants->idOrNull();
        if ($tenantId === null || ! ($this->areas->get($area)['seats'] ?? false)) {
            return null;
        }

        return $this->load($tenantId)['seats'][$area] ?? null;
    }

    public function holdsSeat(User $user, string $area): bool
    {
        $key = $user->tenant_id.':'.$user->id;
        if (! isset($this->held[$key])) {
            $areas = DB::connection(Connections::cell())->table('seat_holders')->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->pluck('area')->all();
            $this->held[$key] = array_values(array_map(fn (mixed $a): string => (string) $a, $areas));
        }

        return in_array($area, $this->held[$key], true);
    }

    /** HQ: the choice for everyone (tenant 0) or for one brokerage. A null state removes HQ's choice. */
    public function setByHq(int $tenantId, string $area, ?string $state, ?int $percent, ?string $note, User $by): void
    {
        $this->guardArea($area);
        if ($state !== null && ! in_array($state, ['on', 'off'], true)) {
            throw ValidationException::withMessages(['state' => 'Choose on or off.']);
        }
        if ($percent !== null && ($tenantId !== 0 || $percent < 0 || $percent > 100)) {
            throw ValidationException::withMessages(['rollout_percent' => 'A gradual roll-out is set for everyone, from 0 to 100%.']);
        }
        $this->write($tenantId, $area, ['hq_state' => $state, 'rollout_percent' => $tenantId === 0 ? $percent : null, 'note' => $note !== null ? mb_substr(trim($note), 0, 200) : null], $by);
        app(AuditLogger::class)->record('core.switch.hq_set', metadata: ['area' => $area, 'tenant' => $tenantId, 'state' => $state, 'percent' => $percent], severity: 'warning',
            tenantId: $tenantId > 0 ? $tenantId : null);
    }

    /** The brokerage switches an area off (or back on) for itself. */
    public function setByBrokerage(string $area, bool $on, User $by): void
    {
        $this->guardArea($area);
        $tenantId = $this->tenants->id();
        if ($on && ! $this->hqAllows($area, $tenantId)) {
            throw ValidationException::withMessages(['area' => 'This is not part of your package yet. Ask RightAlly to switch it on.']);
        }
        $this->write($tenantId, $area, ['brokerage_off' => ! $on], $by);
        app(AuditLogger::class)->record('core.switch.brokerage_set', metadata: ['area' => $area, 'on' => $on]);
    }

    /** HQ: how many seats this brokerage has in an area (null removes the limit). */
    public function setSeats(int $tenantId, string $area, ?int $seats, User $by): void
    {
        $this->guardArea($area);
        if (! ($this->areas->get($area)['seats'] ?? false)) {
            throw ValidationException::withMessages(['seats' => 'This area is not sold by the seat.']);
        }
        $table = DB::connection(Connections::control())->table('tenant_seats');
        if ($seats === null) {
            $table->where('tenant_id', $tenantId)->where('area', $area)->delete();
        } else {
            if ($seats < 0 || $seats > 100_000) {
                throw ValidationException::withMessages(['seats' => 'Enter a number of seats from 0 to 100,000.']);
            }
            $table->updateOrInsert(['tenant_id' => $tenantId, 'area' => $area], ['seats' => $seats, 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->forget($tenantId);
        app(AuditLogger::class)->record('core.seats.limit_set', metadata: ['area' => $area, 'seats' => $seats], severity: 'warning', tenantId: $tenantId);
    }

    /** The owner gives someone a seat (refused when every seat is taken). */
    public function giveSeat(User $user, string $area, User $by): void
    {
        $limit = $this->seatLimit($area);
        if ($limit === null) {
            throw ValidationException::withMessages(['area' => 'Everyone can use this already; no seat is needed.']);
        }
        $db = DB::connection(Connections::cell())->table('seat_holders');
        $base = $db->clone()->where('tenant_id', $this->tenants->id())->where('area', $area);
        if ($base->clone()->where('user_id', $user->id)->exists()) {
            return;
        }
        if ($base->clone()->count() >= $limit) {
            throw ValidationException::withMessages(['area' => 'All '.$limit.' seats are taken. Free a seat first, or ask RightAlly for more.']);
        }
        $db->clone()->insert(['tenant_id' => $this->tenants->id(), 'area' => $area, 'user_id' => $user->id, 'assigned_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        unset($this->held[$user->tenant_id.':'.$user->id]);
        app(AuditLogger::class)->record('core.seats.given', $user, metadata: ['area' => $area], label: $user->name);
    }

    public function takeSeat(User $user, string $area): void
    {
        DB::connection(Connections::cell())->table('seat_holders')->where('tenant_id', $this->tenants->id())->where('area', $area)->where('user_id', $user->id)->delete();
        unset($this->held[$user->tenant_id.':'.$user->id]);
        app(AuditLogger::class)->record('core.seats.taken', $user, metadata: ['area' => $area], label: $user->name);
    }

    /**
     * Everything about the areas for one brokerage, for the settings screens.
     *
     * @return list<array{key: string, label: string, about: string, seats_possible: bool, on: bool, hq_allows: bool, hq_state: string|null, brokerage_off: bool, seats: int|null}>
     */
    public function summary(int $tenantId): array
    {
        $data = $this->load($tenantId);
        $out = [];
        foreach ($this->areas->all() as $key => $area) {
            $own = $data['rows'][$key] ?? null;
            $out[] = ['key' => $key, 'label' => $area['label'], 'about' => $area['about'], 'seats_possible' => $area['seats'], 'on' => $this->on($key, $tenantId),
                'hq_allows' => $this->hqAllows($key, $tenantId), 'hq_state' => $own?->hq_state, 'brokerage_off' => (bool) ($own->brokerage_off ?? false),
                'seats' => $area['seats'] ? ($data['seats'][$key] ?? null) : null];
        }

        return $out;
    }

    /**
     * HQ's choice for everyone, per area.
     *
     * @return list<array{key: string, label: string, about: string, default_on: bool, hq_state: string|null, rollout_percent: int|null, note: string|null}>
     */
    public function everyone(): array
    {
        $rows = DB::connection(Connections::control())->table('feature_switches')->where('tenant_id', 0)->get()->keyBy('area');
        $out = [];
        foreach ($this->areas->all() as $key => $area) {
            $r = $rows->get($key);
            $out[] = ['key' => $key, 'label' => $area['label'], 'about' => $area['about'], 'default_on' => $area['default_on'],
                'hq_state' => $r?->hq_state, 'rollout_percent' => $r !== null && $r->rollout_percent !== null ? (int) $r->rollout_percent : null, 'note' => $r?->note];
        }

        return $out;
    }

    public function forget(?int $tenantId = null): void
    {
        if ($tenantId === null || $tenantId === 0) {
            $this->memo = [];
            Cache::increment('switches:generation');
        } else {
            unset($this->memo[$tenantId]);
            Cache::forget($this->cacheKey($tenantId));
        }
    }

    /** A stable 0–99 number per brokerage and area, so a roll-out keeps the same brokerages as it grows. */
    public static function bucket(int $tenantId, string $area): int
    {
        return (int) (crc32($tenantId.':'.$area) % 100);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(int $tenantId, string $area, array $values, User $by): void
    {
        DB::connection(Connections::control())->table('feature_switches')->updateOrInsert(['tenant_id' => $tenantId, 'area' => $area],
            $values + ['updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        $this->forget($tenantId);
    }

    private function guardArea(string $area): void
    {
        if (! $this->areas->has($area)) {
            throw ValidationException::withMessages(['area' => 'That area does not exist.']);
        }
    }

    private function cacheKey(int $tenantId): string
    {
        return 'switches:'.(int) Cache::get('switches:generation', 0).':'.$tenantId;
    }

    /**
     * @return array{rows: array<string, stdClass>, everyone: array<string, stdClass>, seats: array<string, int>}
     */
    private function load(int $tenantId): array
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }
        /** @var array{rows: array<string, array<string, mixed>>, everyone: array<string, array<string, mixed>>, seats: array<string, int>} $raw */
        $raw = Cache::remember($this->cacheKey($tenantId), self::TTL, function () use ($tenantId): array {
            $control = DB::connection(Connections::control());
            $rows = ['rows' => [], 'everyone' => []];
            foreach ($control->table('feature_switches')->whereIn('tenant_id', [0, $tenantId])->get() as $r) {
                $rows[(int) $r->tenant_id === 0 && $tenantId !== 0 ? 'everyone' : 'rows'][(string) $r->area] = (array) $r;
            }
            $rows['seats'] = $control->table('tenant_seats')->where('tenant_id', $tenantId)->pluck('seats', 'area')->map(fn (mixed $s): int => (int) $s)->all();

            return $rows;
        });

        return $this->memo[$tenantId] = ['rows' => array_map(fn (array $r): stdClass => (object) $r, $raw['rows']),
            'everyone' => array_map(fn (array $r): stdClass => (object) $r, $raw['everyone']), 'seats' => $raw['seats']];
    }
}
