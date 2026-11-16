<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;

/**
 * The permission engine (specs/core/03). A user may do {area}.{resource}.{action} on a record when one
 * of their active (unexpired) roles grants it and the record is within the granted scope.
 *
 * - The Owner (kind owner, or the locked Owner role) has every permission.
 * - Users with no roles assigned keep the access of their user type (no change until an owner
 *   assigns roles): owners everything, HQ staff user management in HQ, everyone else none here.
 * - Separation of duties: when a user somehow holds both sides of a conflicting pair, the second
 *   (the riskier action, e.g. approving payouts) is refused.
 */
final class Permissions
{
    private const RANK = ['own' => 1, 'team' => 2, 'office' => 3, 'tenant' => 4];

    /** @var array<int, array<string, string>> user id => permission => broadest scope */
    private array $cache = [];

    /** @var array<int, bool> user id => access decided by roles (R1) */
    private array $governed = [];

    public function __construct(private readonly PermissionRegistry $registry) {}

    public function allows(User $user, string $key, ?Model $record = null): bool
    {
        if (! $this->registry->has($key)) {
            return false;
        }
        $grants = $this->grants($user);
        if (! array_key_exists($key, $grants)) {
            return false;
        }
        foreach ($this->registry->conflicts() as [$a, $b]) {
            if ($key === $b && array_key_exists($a, $grants)) {
                return false;
            }
        }

        return $record === null || $this->inScope($user, $grants[$key], $record);
    }

    /**
     * Whether this person's access is decided by roles (specs/core/03, R1). Owners never are (they have
     * everything). Staff are once they hold at least one active role; until then they keep the access of
     * their user type. Everyone else always is.
     */
    public function governed(User $user): bool
    {
        if ($user->kind === UserKind::Owner) {
            return false;
        }
        if (isset($this->governed[$user->id])) {
            return $this->governed[$user->id];
        }

        return $this->governed[$user->id] = $user->kind !== UserKind::Staff || $this->activeRoles($user)->isNotEmpty();
    }

    /**
     * The manager check used by every module (R1): owners always; staff without roles keep their
     * type's access; anyone whose access is decided by roles needs the permission.
     */
    public function manages(User $user, string $key): bool
    {
        if ($user->kind === UserKind::Owner) {
            return true;
        }
        if ($user->kind === UserKind::Staff && ! $this->governed($user)) {
            return true;
        }

        return $this->allows($user, $key);
    }

    /**
     * Run $fn with a pretend person holding exactly these grants (preview as a role). Nothing is saved.
     *
     * @template T
     *
     * @param  array<string, string>  $grants
     * @param  callable(User): T  $fn
     * @return T
     */
    public function simulate(User $pretend, array $grants, callable $fn): mixed
    {
        $saved = [$this->cache[$pretend->id] ?? null, $this->governed[$pretend->id] ?? null];
        $this->cache[$pretend->id] = $grants;
        $this->governed[$pretend->id] = true;
        try {
            return $fn($pretend);
        } finally {
            unset($this->cache[$pretend->id], $this->governed[$pretend->id]);
            if ($saved[0] !== null) {
                $this->cache[$pretend->id] = $saved[0];
            }
            if ($saved[1] !== null) {
                $this->governed[$pretend->id] = $saved[1];
            }
        }
    }

    /**
     * "Why can I see this?" (R1): the plain-English reasons a person has a permission on a record, or
     * why not.
     *
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function explain(User $user, string $key, ?Model $record = null, ?string $ownerName = null): array
    {
        $label = lcfirst($this->registry->get($key)['label'] ?? $key);
        if ($user->kind === UserKind::Owner) {
            return ['allowed' => true, 'reasons' => ['You are an owner of the brokerage, so you can '.$label.' for everyone.']];
        }
        $owner = $record?->getAttribute('owner_user_id') ?? $record?->getAttribute('assigned_to') ?? $record?->getAttribute('user_id');
        $reasons = [];
        if ($owner !== null && (int) $owner === $user->id) {
            $reasons[] = 'It is yours: you are the person it belongs to.';
        }
        if ($user->kind === UserKind::Staff && ! $this->governed($user)) {
            $reasons[] = 'You are brokerage staff with no role assigned yet, so you keep the staff access the brokerage started with.';

            return ['allowed' => true, 'reasons' => $reasons];
        }
        $where = ['own' => 'for your own records', 'team' => 'for your team', 'office' => 'for your office', 'tenant' => 'for everyone'];
        foreach ($this->activeRoles($user) as $role) {
            $scope = DB::connection(Connections::cell())->table('role_permissions')->where('role_id', $role->id)->where('permission_key', $key)->value('scope');
            $has = DB::connection(Connections::cell())->table('role_permissions')->where('role_id', $role->id)->where('permission_key', $key)->exists();
            if (! $has) {
                continue;
            }
            $scope = (string) ($scope ?? 'tenant');
            $line = "Your role {$role->name} lets you {$label}".(($this->registry->get($key)['scoped'] ?? false) ? ' '.$where[$scope] : '').'.';
            $expires = UserRole::query()->where('user_id', $user->id)->where('role_id', $role->id)->value('expires_at');
            if ($expires !== null) {
                $line .= ' This access ends on '.CarbonImmutable::parse((string) $expires)->toFormattedDateString().'.';
            }
            $reasons[] = $line;
            if ($record !== null && $owner !== null && (int) $owner !== $user->id && in_array($scope, ['team', 'office'], true)) {
                $reasons[] = ($ownerName ?? 'The person it belongs to').' is in your '.$scope.'.';
            }
        }
        $allowed = $this->allows($user, $key, $record) || ($owner !== null && (int) $owner === $user->id);
        if (! $allowed) {
            $reasons = ['None of your roles lets you '.$label.' here. Ask an owner if you need it.'];
        }

        return ['allowed' => $allowed, 'reasons' => $reasons];
    }

    /**
     * Broadest scope the user holds for a permission ('tenant' for unscoped grants), or null.
     */
    public function scopeFor(User $user, string $key): ?string
    {
        return $this->grants($user)[$key] ?? null;
    }

    /**
     * @return array<string, string> permission => broadest scope
     */
    public function grants(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $roles = $this->activeRoles($user);
        if ($user->kind === UserKind::Owner || $roles->contains(fn (Role $r): bool => $r->is_locked)) {
            return $this->cache[$user->id] = array_fill_keys(array_keys($this->registry->all()), 'tenant');
        }
        if ($roles->isEmpty()) {
            return $this->cache[$user->id] = $user->kind === UserKind::HqStaff
                ? ['core.users.view' => 'tenant', 'core.users.invite' => 'tenant', 'core.users.manage' => 'tenant']
                : [];
        }

        $grants = [];
        $rows = DB::connection(Connections::cell())->table('role_permissions')->whereIn('role_id', $roles->pluck('id'))->get();
        foreach ($rows as $row) {
            if (! $this->registry->has((string) $row->permission_key)) {
                continue;
            }
            $scope = (string) ($row->scope ?? 'tenant');
            $current = $grants[$row->permission_key] ?? null;
            if ($current === null || self::RANK[$scope] > self::RANK[$current]) {
                $grants[(string) $row->permission_key] = $scope;
            }
        }

        return $this->cache[$user->id] = $grants;
    }

    /**
     * @return Collection<int, Role>
     */
    public function activeRoles(User $user): Collection
    {
        $ids = UserRole::query()->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()))->pluck('role_id');

        return Role::query()->whereIn('id', $ids)->get();
    }

    /**
     * User ids within a scope around $user (null = everyone).
     *
     * @return list<int>|null
     */
    public function peopleInScope(User $user, string $scope): ?array
    {
        return match ($scope) {
            'tenant' => null,
            'office' => array_values(array_unique(array_merge([$user->id], DB::connection(Connections::cell())->table('user_offices')
                ->whereIn('office_id', DB::connection(Connections::cell())->table('user_offices')->where('user_id', $user->id)->pluck('office_id'))
                ->pluck('user_id')->map(fn ($v): int => (int) $v)->all()))),
            'team' => array_values(array_unique(array_merge([$user->id], DB::connection(Connections::cell())->table('team_members')
                ->whereIn('team_id', $this->teamIds($user))->pluck('user_id')->map(fn ($v): int => (int) $v)->all()))),
            default => [$user->id],
        };
    }

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->cache = [];
            $this->governed = [];
        } else {
            unset($this->cache[$user->id], $this->governed[$user->id]);
        }
    }

    private function inScope(User $user, string $scope, Model $record): bool
    {
        $owner = $record->getAttribute('owner_user_id') ?? $record->getAttribute('assigned_to') ?? $record->getAttribute('user_id');
        $people = $this->peopleInScope($user, $scope);

        return $people === null || ($owner !== null && in_array((int) $owner, $people, true));
    }

    /**
     * @return list<int>
     */
    private function teamIds(User $user): array
    {
        $db = DB::connection(Connections::cell());

        return array_values(array_unique(array_merge(
            $db->table('team_members')->where('user_id', $user->id)->pluck('team_id')->map(fn ($v): int => (int) $v)->all(),
            $db->table('teams')->where('leader_user_id', $user->id)->whereNull('deleted_at')->pluck('id')->map(fn ($v): int => (int) $v)->all(),
        )));
    }
}
