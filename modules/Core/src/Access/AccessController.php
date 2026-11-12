<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * Roles (plain-English editor with preview), role assignment, offices and teams.
 */
final class AccessController
{
    public function __construct(private readonly Roles $roles, private readonly PermissionRegistry $registry) {}

    public function roles(Request $request): Response
    {
        $this->authorise($request, 'core.roles.manage');
        $roles = $this->roles->all();
        $holders = DB::connection(Connections::cell())->table('user_roles')->selectRaw('role_id, count(*) as n')->groupBy('role_id')->pluck('n', 'role_id');

        return Inertia::render('settings/roles', [
            'roles' => $roles->map(fn (Role $r): array => [
                'ulid' => $r->ulid, 'name' => $r->name, 'description' => $r->description, 'locked' => $r->is_locked,
                'template' => $r->template_key, 'members' => (int) ($holders[$r->id] ?? 0), 'permissions' => (object) $this->roles->permissionsOf($r),
                'preview' => $this->roles->describe($this->roles->permissionsOf($r), $r->is_locked),
            ])->values(),
            'catalogue' => collect($this->registry->all())->groupBy('area')->map(fn ($items, $area): array => ['area' => $area, 'permissions' => $items->values()])->values(),
            'conflicts' => array_map(fn (array $c): array => ['a' => $c[0], 'b' => $c[1], 'why' => $c[2]], $this->registry->conflicts()),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorise($request, 'core.roles.manage');
        $data = $request->validate(['permissions' => ['array']]);
        /** @var array<string, string|null> $perms */
        $perms = array_filter((array) ($data['permissions'] ?? []), fn ($v, $k) => $this->registry->has((string) $k), ARRAY_FILTER_USE_BOTH);

        return response()->json(['lines' => $this->roles->describe($perms), 'conflicts' => $this->registry->conflictsIn(array_keys($perms))]);
    }

    public function save(Request $request, ?Role $role = null): RedirectResponse
    {
        $user = $this->authorise($request, 'core.roles.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'], 'permissions' => ['array']]);
        $this->roles->save($role, $data['name'], $data['description'] ?? null, (array) ($data['permissions'] ?? []), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role saved.']);

        return back();
    }

    public function assign(Request $request, User $user): RedirectResponse
    {
        $by = $this->authorise($request, 'core.roles.manage');
        $data = $request->validate(['role' => ['required', 'string', 'size:26'], 'expires_at' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:200']]);
        $role = Role::query()->where('ulid', $data['role'])->firstOrFail();
        $this->roles->assign($user, $role, $by, isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at'])->endOfDay() : null, $data['reason'] ?? null);

        return back();
    }

    public function revoke(Request $request, User $user, Role $role): RedirectResponse
    {
        $this->authorise($request, 'core.roles.manage');
        $this->roles->revoke($user, $role);

        return back();
    }

    public function organisation(Request $request): Response
    {
        $this->authorise($request, 'core.organisation.manage');
        $db = DB::connection(Connections::cell());

        return Inertia::render('settings/organisation', [
            'offices' => Office::query()->orderByDesc('is_primary')->orderBy('name')->get()->map(fn (Office $o): array => [
                'ulid' => $o->ulid, 'name' => $o->name, 'primary' => $o->is_primary,
                'people' => $db->table('user_offices')->where('office_id', $o->id)->count(),
            ]),
            'teams' => Team::query()->orderBy('name')->get()->map(fn (Team $t): array => [
                'ulid' => $t->ulid, 'name' => $t->name, 'office' => $t->office_id !== null ? Office::query()->whereKey($t->office_id)->value('name') : null,
                'leader' => $t->leader_user_id !== null ? User::query()->find($t->leader_user_id)?->name : null,
                'members' => User::query()->whereIn('id', $db->table('team_members')->where('team_id', $t->id)->pluck('user_id'))->get()->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name])->values(),
            ]),
            'people' => User::query()->where('status', 'active')->orderBy('first_name')->get()->map(fn (User $u): array => [
                'ulid' => $u->ulid, 'name' => $u->name, 'offices' => $db->table('user_offices')->where('user_id', $u->id)->pluck('office_id')->map(fn ($id) => Office::query()->whereKey($id)->value('ulid'))->values(),
            ]),
        ]);
    }

    public function saveOffice(Request $request): RedirectResponse
    {
        $this->authorise($request, 'core.organisation.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:80'], 'state_code' => ['nullable', 'string', 'size:2']]);
        $office = Office::query()->create($data + ['is_primary' => ! Office::query()->exists()]);
        app(AuditLogger::class)->record('core.office.created', $office, label: $office->name);

        return back();
    }

    public function saveTeam(Request $request): RedirectResponse
    {
        $this->authorise($request, 'core.organisation.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'office' => ['nullable', 'string', 'size:26'], 'leader' => ['nullable', 'string', 'size:26']]);
        $team = Team::query()->create([
            'name' => $data['name'], 'office_id' => isset($data['office']) ? Office::query()->where('ulid', $data['office'])->value('id') : null,
            'leader_user_id' => isset($data['leader']) ? User::query()->where('ulid', $data['leader'])->value('id') : null,
        ]);
        app(AuditLogger::class)->record('core.team.created', $team, label: $team->name);

        return back();
    }

    public function membership(Request $request): RedirectResponse
    {
        $this->authorise($request, 'core.organisation.manage');
        $data = $request->validate(['user' => ['required', 'string', 'size:26'], 'team' => ['nullable', 'string', 'size:26'], 'office' => ['nullable', 'string', 'size:26'], 'remove' => ['nullable', 'boolean']]);
        $user = User::query()->where('ulid', $data['user'])->firstOrFail();
        $db = DB::connection(Connections::cell());
        if (isset($data['team'])) {
            $team = Team::query()->where('ulid', $data['team'])->firstOrFail();
            ($data['remove'] ?? false)
                ? $db->table('team_members')->where('team_id', $team->id)->where('user_id', $user->id)->delete()
                : $db->table('team_members')->insertOrIgnore(['tenant_id' => $team->getAttribute('tenant_id'), 'team_id' => $team->id, 'user_id' => $user->id, 'joined_at' => now()]);
        }
        if (isset($data['office'])) {
            $office = Office::query()->where('ulid', $data['office'])->firstOrFail();
            ($data['remove'] ?? false)
                ? $db->table('user_offices')->where('office_id', $office->id)->where('user_id', $user->id)->delete()
                : $db->table('user_offices')->insertOrIgnore(['tenant_id' => $office->getAttribute('tenant_id'), 'user_id' => $user->id, 'office_id' => $office->id, 'is_primary' => false]);
        }
        app(Permissions::class)->forget();
        app(AuditLogger::class)->record('core.organisation.membership_changed', $user, metadata: array_filter(['team' => $data['team'] ?? null, 'office' => $data['office'] ?? null, 'removed' => $data['remove'] ?? false]), label: $user->name);

        return back();
    }

    private function authorise(Request $request, string $permission): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can($permission), 403);

        return $user;
    }
}
