<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Hq;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\HqAccess;
use Modules\Core\Access\HqRoles;
use Modules\Core\Access\Role;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;

/** HQ2: Settings › Roles in the HQ console: the ready-made and custom HQ roles, and each HQ person's role. */
final class RolesController
{
    public function index(HqRoles $roles): Response
    {
        $list = $roles->roles();
        $people = User::query()->where('kind', UserKind::HqStaff)->where('status', '!=', UserStatus::Deactivated)->orderBy('first_name')->get();

        return Inertia::render('hq/roles', [
            'roles' => $list->map(fn (Role $r): array => ['ulid' => $r->ulid, 'name' => $r->name, 'description' => $r->description,
                'super' => $r->template_key === HqRoles::SUPER, 'ready_made' => $r->template_key !== null, 'permissions' => $roles->keys($r)])->values(),
            'people' => $people->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name, 'email' => $u->email, 'role' => $roles->roleOf($u)?->ulid])->values(),
            'areas' => collect(HqAccess::AREAS)->map(fn (array $a, string $key): array => ['key' => $key, 'label' => $a[0]])->values(),
            'extras' => [['key' => HqAccess::SUPPORT_ACCESS, 'label' => HqAccess::permissions()[HqAccess::SUPPORT_ACCESS][0]],
                ['key' => HqAccess::DATA_EXPORT, 'label' => HqAccess::permissions()[HqAccess::DATA_EXPORT][0]]],
            'superOnly' => HqAccess::SUPER_ONLY,
        ]);
    }

    public function save(Request $request, HqRoles $roles, ?string $role = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'],
            'permissions' => ['array'], 'permissions.*' => ['string', 'max:60']]);
        $existing = $role !== null ? Role::query()->where('ulid', $role)->firstOrFail() : null;
        $saved = $roles->save($existing, $data['name'], $data['description'] ?? null, array_values($data['permissions'] ?? []), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved the {$saved->name} role."]);

        return back();
    }

    public function delete(Request $request, HqRoles $roles, string $role): RedirectResponse
    {
        $roles->delete(Role::query()->where('ulid', $role)->firstOrFail(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role removed.']);

        return back();
    }

    public function assign(Request $request, HqRoles $roles, string $person): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', 'string', 'size:26']]);
        $who = User::query()->where('ulid', $person)->where('kind', UserKind::HqStaff)->firstOrFail();
        $role = Role::query()->where('ulid', $data['role'])->firstOrFail();
        $roles->assign($who, $role, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$who->name} is now {$role->name}."]);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
