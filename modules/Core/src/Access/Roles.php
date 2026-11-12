<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;

/**
 * Roles and grants (specs/core/03): templates seeded once per brokerage, editing with separation-of-duty
 * checks, assignments with optional end dates, and plain-English previews.
 */
final class Roles
{
    public function __construct(private readonly PermissionRegistry $registry, private readonly Permissions $permissions, private readonly AuditLogger $audit) {}

    /**
     * @return Collection<int, Role>
     */
    public function all(): Collection
    {
        if (! Role::query()->exists()) {
            DB::connection(Connections::cell())->transaction(function (): void {
                foreach (RoleTemplates::all() as $key => $t) {
                    $role = Role::query()->create(['key' => $key, 'name' => $t['name'], 'description' => $t['description'], 'template_key' => $key, 'is_locked' => $t['locked'] ?? false]);
                    $this->writePermissions($role, array_filter($t['permissions'], fn ($scope, $perm) => $this->registry->has($perm), ARRAY_FILTER_USE_BOTH));
                }
            });
        }

        return Role::query()->orderByDesc('is_locked')->orderBy('name')->get();
    }

    /**
     * @param  array<string, string|null>  $permissions  key => scope (null for unscoped)
     */
    public function save(?Role $role, string $name, ?string $description, array $permissions, User $by): Role
    {
        if ($role?->is_locked) {
            throw ValidationException::withMessages(['role' => 'The Owner role cannot be changed.']);
        }
        $clean = [];
        foreach ($permissions as $key => $scope) {
            $def = $this->registry->get($key);
            if ($def === null) {
                continue;
            }
            $clean[$key] = $def['scoped'] ? (in_array($scope, PermissionRegistry::SCOPES, true) ? $scope : 'own') : null;
        }
        $conflicts = $this->registry->conflictsIn(array_keys($clean));
        if ($conflicts !== []) {
            throw ValidationException::withMessages(['permissions' => $conflicts[0]]);
        }
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'Give the role a name.']);
        }

        return DB::connection(Connections::cell())->transaction(function () use ($role, $name, $description, $clean, $by): Role {
            $before = $role !== null ? $this->permissionsOf($role) : [];
            $role ??= new Role(['key' => Str::slug($name, '_').'_'.Str::lower(Str::random(4)), 'created_by' => $by->id]);
            $role->fill(['name' => mb_substr(trim($name), 0, 80), 'description' => $description !== null ? mb_substr($description, 0, 300) : null])->save();
            $this->writePermissions($role, $clean);

            foreach (UserRole::query()->where('role_id', $role->id)->pluck('user_id') as $userId) {
                $holder = User::query()->find($userId);
                if ($holder instanceof User && ($why = $this->registry->conflictsIn(array_keys($this->unionFor($holder)))) !== []) {
                    throw ValidationException::withMessages(['permissions' => "{$holder->name}: ".$why[0]]);
                }
            }
            $this->permissions->forget();
            $this->audit->record('core.role.saved', $role, changes: ['permissions' => ['old' => $before, 'new' => $clean]], severity: 'warning', label: $role->name);

            return $role;
        });
    }

    public function assign(User $user, Role $role, User $by, ?CarbonImmutable $expiresAt = null, ?string $reason = null): UserRole
    {
        if ($role->is_locked && $user->kind !== UserKind::Owner) {
            throw ValidationException::withMessages(['role' => 'Only owners hold the Owner role.']);
        }
        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw ValidationException::withMessages(['expires_at' => 'Choose a date in the future.']);
        }
        $union = array_keys($this->unionFor($user) + $this->permissionsOf($role));
        if (($why = $this->registry->conflictsIn($union)) !== []) {
            throw ValidationException::withMessages(['role' => $why[0]]);
        }

        $grant = UserRole::query()->updateOrCreate(['user_id' => $user->id, 'role_id' => $role->id], [
            'granted_by' => $by->id, 'granted_at' => CarbonImmutable::now(), 'expires_at' => $expiresAt, 'reason' => $reason,
        ]);
        $this->permissions->forget($user);
        $this->audit->record('core.role.granted', $user, metadata: ['role' => $role->name, 'expires_at' => $expiresAt?->toDateString(), 'reason' => $reason], severity: 'warning', label: $user->name);

        return $grant;
    }

    public function revoke(User $user, Role $role, string $why = 'removed'): void
    {
        UserRole::query()->where('user_id', $user->id)->where('role_id', $role->id)->delete();
        $this->permissions->forget($user);
        $this->audit->record('core.role.revoked', $user, metadata: ['role' => $role->name, 'why' => $why], severity: 'warning', label: $user->name);
    }

    public function expire(): int
    {
        $count = 0;
        foreach (UserRole::query()->whereNotNull('expires_at')->where('expires_at', '<=', CarbonImmutable::now())->get() as $grant) {
            $user = User::query()->find($grant->user_id);
            $role = Role::query()->find($grant->role_id);
            if ($user instanceof User && $role instanceof Role) {
                $this->audit->asAutomation('access_expiry', $user->id, 'Remove expired access', fn () => $this->revoke($user, $role, 'expired'));
                $count++;
            } else {
                $grant->delete();
            }
        }

        return $count;
    }

    /**
     * @return array<string, string|null>
     */
    public function permissionsOf(Role $role): array
    {
        return DB::connection(Connections::cell())->table('role_permissions')->where('role_id', $role->id)->pluck('scope', 'permission_key')->all();
    }

    /**
     * Plain-English preview: what someone with this set of permissions can do.
     *
     * @param  array<string, string|null>  $permissions
     * @return list<string>
     */
    public function describe(array $permissions, bool $locked = false): array
    {
        if ($locked) {
            return ['Can do everything in the brokerage.'];
        }
        $where = ['own' => 'for their own records', 'team' => 'for their team', 'office' => 'for their office', 'tenant' => 'for everyone'];
        $lines = [];
        foreach ($permissions as $key => $scope) {
            $def = $this->registry->get($key);
            if ($def !== null) {
                $lines[] = 'Can '.lcfirst($def['label']).($def['scoped'] ? ' '.$where[$scope ?? 'own'] : '').'.';
            }
        }
        $sensitive = array_filter($this->registry->all(), fn (array $d): bool => $d['sensitive'] && ! array_key_exists($d['key'], $permissions));
        foreach ($sensitive as $d) {
            $lines[] = 'Cannot '.lcfirst($d['label']).'.';
        }

        return $lines === [] ? ['Has no extra access beyond their own work.'] : $lines;
    }

    /**
     * @return array<string, string|null>
     */
    private function unionFor(User $user): array
    {
        $union = [];
        foreach ($this->permissions->activeRoles($user) as $role) {
            $union += $this->permissionsOf($role);
        }

        return $union;
    }

    /**
     * @param  array<string, string|null>  $permissions
     */
    private function writePermissions(Role $role, array $permissions): void
    {
        $db = DB::connection(Connections::cell());
        $db->table('role_permissions')->where('role_id', $role->id)->delete();
        foreach ($permissions as $key => $scope) {
            $db->table('role_permissions')->insert(['tenant_id' => $role->tenant_id, 'role_id' => $role->id, 'permission_key' => $key, 'scope' => $scope]);
        }
    }
}
