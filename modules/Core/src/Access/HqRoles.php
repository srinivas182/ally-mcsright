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
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;

/**
 * HQ2: HQ's roles. Seven ready-made roles, plus any HQ makes itself. Each HQ person holds one role. Super admin has
 * everything, cannot be changed, and at least one person always holds it.
 */
final class HqRoles
{
    public const SUPER = 'hq_super_admin';

    /** The account that becomes Super admin when HQ's roles are first set up. */
    public const FIRST_SUPER_ADMIN = 'info@rightally.io';

    public function __construct(private readonly Permissions $permissions, private readonly AuditLogger $audit) {}

    /**
     * The ready-made HQ roles: key => name, description, areas it may change, areas it may only see, extras.
     *
     * @return array<string, array{name: string, description: string, manage: list<string>, view: list<string>, extra?: list<string>}>
     */
    public static function templates(): array
    {
        $all = array_keys(HqAccess::AREAS);

        return [
            self::SUPER => ['name' => 'Super admin', 'description' => 'Everything in HQ, including money rails, keys and who can do what. Cannot be changed.',
                'manage' => $all, 'view' => [], 'extra' => [HqAccess::SUPPORT_ACCESS, HqAccess::DATA_EXPORT]],
            'hq_support' => ['name' => 'Support', 'description' => 'Helps brokerages: customers, the support inbox, websites and MLS, Movewise.',
                'manage' => ['customers', 'support', 'movewise'], 'view' => ['websites', 'mls']],
            'hq_sales' => ['name' => 'Sales', 'description' => 'Brings in brokerages: sign-ups, go-live and health checks; sees prices.',
                'manage' => ['customers'], 'view' => ['billing']],
            'hq_finance' => ['name' => 'Finance', 'description' => 'Packages, prices, credits and the referral programme; sees payments and payout rails.',
                'manage' => ['billing'], 'view' => ['payments', 'customers', 'movewise']],
            'hq_content' => ['name' => 'Content and MLS', 'description' => 'RightAlly\'s website, the content desk, brokerage websites, MLS and IDX, products.',
                'manage' => ['websites', 'mls', 'products'], 'view' => ['customers']],
            'hq_engineering' => ['name' => 'Engineering', 'description' => 'AI, system health and cells; sees connections (keys stay with Super admin).',
                'manage' => ['ai', 'system'], 'view' => ['connections', 'customers']],
            'hq_view_only' => ['name' => 'View only', 'description' => 'Sees everything in HQ, changes nothing.',
                'manage' => [], 'view' => array_values(array_diff($all, ['settings']))],
        ];
    }

    /**
     * Set up HQ's roles the first time: the ready-made roles, and info@rightally.io as Super admin. Later calls add
     * ready-made roles that are missing and change nothing else.
     */
    public function ensure(): void
    {
        $first = ! HqAccess::rolesOn();
        DB::connection(Connections::cell())->transaction(function () use ($first): void {
            foreach (self::templates() as $key => $t) {
                if (Role::query()->where('template_key', $key)->exists()) {
                    continue;
                }
                $role = Role::query()->create(['key' => $key, 'name' => $t['name'], 'description' => $t['description'], 'template_key' => $key, 'is_locked' => false]);
                $this->write($role, self::grantsFor($t['manage'], $t['view'], $t['extra'] ?? []));
            }
            if ($first) {
                // info@rightally.io becomes Super admin. If that account is not here, every active HQ person does,
                // so nobody is locked out; the Super admin then gives everyone else their role.
                $super = Role::query()->where('template_key', self::SUPER)->firstOrFail();
                $staff = User::query()->where('kind', UserKind::HqStaff)->where('status', UserStatus::Active)->get();
                $chosen = $staff->filter(fn (User $u): bool => mb_strtolower((string) $u->email) === self::FIRST_SUPER_ADMIN);
                foreach ($chosen->isNotEmpty() ? $chosen : $staff as $person) {
                    UserRole::query()->firstOrCreate(['user_id' => $person->id, 'role_id' => $super->id], ['granted_at' => now(), 'reason' => 'HQ roles set up']);
                }
            }
        });
        $this->permissions->forget();
        if ($first) {
            $this->audit->record('core.hq_roles.set_up', metadata: ['super_admins' => $this->superAdmins()->pluck('email')->all()], severity: 'warning');
        }
    }

    /**
     * Roles with their permissions and who holds them.
     *
     * @return Collection<int, Role>
     */
    public function roles(): Collection
    {
        $this->ensure();

        return Role::query()->where(fn ($q) => $q->whereIn('template_key', array_keys(self::templates()))->orWhere('key', 'like', 'hqc_%'))
            ->orderByRaw('case when template_key = ? then 0 else 1 end', [self::SUPER])->orderBy('name')->get();
    }

    /**
     * Save a custom role (or change a ready-made one other than Super admin).
     *
     * @param  list<string>  $permissions  hq.* keys
     */
    public function save(?Role $role, string $name, ?string $description, array $permissions, User $by): Role
    {
        if ($role !== null && $role->template_key === self::SUPER) {
            throw ValidationException::withMessages(['role' => 'Super admin cannot be changed.']);
        }
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'Give the role a name.']);
        }
        $known = HqAccess::permissions();
        $clean = array_values(array_unique(array_filter($permissions, fn (string $p): bool => isset($known[$p]))));
        if (($super = array_values(array_intersect($clean, HqAccess::SUPER_ONLY))) !== []) {
            throw ValidationException::withMessages(['permissions' => 'Only Super admin can '.mb_strtolower($known[$super[0]][0]).'.']);
        }

        return DB::connection(Connections::cell())->transaction(function () use ($role, $name, $description, $clean, $by): Role {
            $before = $role !== null ? $this->keys($role) : [];
            $role ??= new Role(['key' => 'hqc_'.Str::slug($name, '_').'_'.Str::lower(Str::random(4)), 'created_by' => $by->id]);
            $role->fill(['name' => mb_substr(trim($name), 0, 80), 'description' => $description !== null ? mb_substr($description, 0, 300) : null])->save();
            $this->write($role, array_fill_keys($clean, null));
            $this->permissions->forget();
            $this->audit->record('core.hq_role.saved', $role, changes: ['permissions' => ['old' => $before, 'new' => $clean]], severity: 'warning', label: $role->name);

            return $role;
        });
    }

    /** Remove a custom role nobody holds. */
    public function delete(Role $role, User $by): void
    {
        if ($role->template_key !== null) {
            throw ValidationException::withMessages(['role' => 'Ready-made roles stay; change what they can do instead.']);
        }
        if (UserRole::query()->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Give the people in this role another role first.']);
        }
        $role->delete();
        $this->audit->record('core.hq_role.deleted', $role, severity: 'warning', label: $role->name);
    }

    /** Give an HQ person their one role. Super admin always keeps at least one holder. */
    public function assign(User $person, Role $role, User $by): void
    {
        if ($person->kind !== UserKind::HqStaff) {
            throw ValidationException::withMessages(['person' => 'Only RightAlly HQ staff hold HQ roles.']);
        }
        $super = Role::query()->where('template_key', self::SUPER)->firstOrFail();
        $holdsSuper = UserRole::query()->where('user_id', $person->id)->where('role_id', $super->id)->exists();
        if ($holdsSuper && $role->id !== $super->id && $this->superAdmins()->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Keep at least one Super admin. Make someone else Super admin first.']);
        }
        $before = $this->roleOf($person)?->name;
        DB::connection(Connections::cell())->transaction(function () use ($person, $role, $by): void {
            UserRole::query()->where('user_id', $person->id)->whereIn('role_id', $this->roles()->pluck('id'))->delete();
            UserRole::query()->create(['user_id' => $person->id, 'role_id' => $role->id, 'granted_by' => $by->id, 'granted_at' => CarbonImmutable::now()]);
        });
        $this->permissions->forget($person);
        $this->audit->record('core.hq_role.assigned', $person, changes: ['role' => ['old' => $before, 'new' => $role->name]], severity: 'warning', label: $person->name);
    }

    public function roleOf(User $person): ?Role
    {
        $ids = UserRole::query()->where('user_id', $person->id)->pluck('role_id');

        return Role::query()->whereIn('id', $ids)->where(fn ($q) => $q->whereIn('template_key', array_keys(self::templates()))->orWhere('key', 'like', 'hqc_%'))->first();
    }

    /** @return list<string> */
    public function keys(Role $role): array
    {
        return array_values(DB::connection(Connections::cell())->table('role_permissions')->where('role_id', $role->id)
            ->where('permission_key', 'like', 'hq.%')->pluck('permission_key')->map(fn ($k): string => (string) $k)->all());
    }

    /** @return Collection<int, User> */
    public function superAdmins(): Collection
    {
        $super = Role::query()->where('template_key', self::SUPER)->first();
        if ($super === null) {
            return collect();
        }

        return User::query()->whereIn('id', UserRole::query()->where('role_id', $super->id)->pluck('user_id'))->where('status', UserStatus::Active)->get();
    }

    /**
     * @param  list<string>  $manage
     * @param  list<string>  $view
     * @param  list<string>  $extra
     * @return array<string, null>
     */
    private static function grantsFor(array $manage, array $view, array $extra): array
    {
        $out = [];
        foreach ($manage as $area) {
            $out["hq.{$area}.change"] = null;
            $out["hq.{$area}.see"] = null;
        }
        foreach ($view as $area) {
            $out["hq.{$area}.see"] = null;
        }
        foreach ($extra as $key) {
            $out[$key] = null;
        }

        return $out;
    }

    /**
     * Write a role's permissions. The HQ team page uses the brokerage people tools, so seeing or changing HQ's
     * settings also gives the matching people permissions.
     *
     * @param  array<string, null>  $permissions
     */
    private function write(Role $role, array $permissions): void
    {
        if (array_key_exists('hq.settings.change', $permissions)) {
            $permissions += ['core.users.view' => null, 'core.users.invite' => null, 'core.users.manage' => null];
        } elseif (array_key_exists('hq.settings.see', $permissions)) {
            $permissions += ['core.users.view' => null];
        }
        $db = DB::connection(Connections::cell());
        $db->table('role_permissions')->where('role_id', $role->id)->delete();
        foreach (array_keys($permissions) as $key) {
            $db->table('role_permissions')->insert(['tenant_id' => $role->tenant_id, 'role_id' => $role->id, 'permission_key' => $key, 'scope' => null]);
        }
    }
}
