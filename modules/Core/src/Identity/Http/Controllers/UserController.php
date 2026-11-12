<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\Access\UserRole;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Actions\ManageUserStatus;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Identity\Security\SignInSecurity;
use Modules\Core\Tenancy\TenantContext;

/**
 * Users admin: people in this tenant, open invitations, and status changes.
 */
final class UserController
{
    public function index(Request $request, TenantContext $tenants): Response
    {
        Gate::authorize('core.users.view');

        $search = trim((string) $request->query('search', ''));
        $kind = (string) $request->query('kind', '');
        $status = (string) $request->query('status', '');

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', '%'.mb_strtolower($search).'%')))
            ->when(UserKind::tryFrom($kind) !== null, fn ($q) => $q->where('kind', $kind))
            ->when(UserStatus::tryFrom($status) !== null, fn ($q) => $q->where('status', $status))
            ->orderBy('first_name')->orderBy('last_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $u): array => [
                'ulid' => $u->ulid,
                'name' => $u->name,
                'email' => $u->email,
                'kind' => $u->kind->value,
                'kind_label' => $u->kind->label(),
                'status' => $u->status->value,
                'last_active_at' => $u->last_active_at?->toIso8601String(),
                'is_me' => $u->is($request->user()),
            ]);

        $invitations = Invitation::query()
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->orderByDesc('id')->get()
            ->map(fn (Invitation $i): array => [
                'ulid' => $i->ulid,
                'email' => $i->email,
                'name' => trim($i->first_name.' '.$i->last_name),
                'kind_label' => $i->kind->label(),
                'expires_at' => $i->expires_at->toIso8601String(),
                'expired' => $i->expires_at->isPast(),
            ]);

        $isHq = $tenants->current()->isHq();

        return Inertia::render('users/index', [
            'users' => $users,
            'invitations' => $invitations,
            'filters' => ['search' => $search, 'kind' => $kind, 'status' => $status],
            'kinds' => array_values(array_map(
                fn (UserKind $k): array => ['value' => $k->value, 'label' => $k->label()],
                array_filter(UserKind::cases(), fn (UserKind $k): bool => $isHq ? $k === UserKind::HqStaff : $k !== UserKind::HqStaff),
            )),
            'agents' => User::query()->where('kind', UserKind::Agent)->where('status', UserStatus::Active)
                ->orderBy('first_name')->get()->map(fn (User $a): array => ['id' => $a->id, 'name' => $a->name]),
            'can' => ['invite' => Gate::allows('core.users.invite'), 'manage' => Gate::allows('core.users.manage'), 'roles' => Gate::allows('core.roles.manage')],
            'roles' => Gate::allows('core.roles.manage') && ! $isHq ? app(Roles::class)->all()->map(fn ($r): array => ['ulid' => $r->ulid, 'name' => $r->name, 'locked' => $r->is_locked])->values() : [],
            'grants' => Gate::allows('core.roles.manage') && ! $isHq ? UserRole::query()->get()->groupBy(fn ($ur) => (string) User::query()->whereKey($ur->user_id)->value('ulid'))->map(fn ($g) => $g->map(fn ($ur): array => [
                'role' => Role::query()->whereKey($ur->role_id)->value('ulid'),
                'name' => Role::query()->whereKey($ur->role_id)->value('name'),
                'expires_at' => $ur->expires_at?->toDateString(),
            ])->values()) : (object) [],
        ]);
    }

    public function invite(Request $request, ManageInvitations $invitations): RedirectResponse
    {
        Gate::authorize('core.users.invite');

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::enum(UserKind::class)],
            'assisted_user_id' => ['nullable', 'integer'],
        ]);

        $invitations->invite(
            $data['email'], $data['first_name'], $data['last_name'], UserKind::from($data['kind']),
            self::actor($request), isset($data['assisted_user_id']) ? (int) $data['assisted_user_id'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Invitation sent to {$data['email']}."]);

        return back();
    }

    public function resend(Request $request, Invitation $invitation, ManageInvitations $invitations): RedirectResponse
    {
        Gate::authorize('core.users.invite');
        $invitations->resend($invitation, self::actor($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitation sent again. The earlier link no longer works.']);

        return back();
    }

    public function revoke(Request $request, Invitation $invitation, ManageInvitations $invitations): RedirectResponse
    {
        Gate::authorize('core.users.invite');
        $invitations->revoke($invitation, self::actor($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitation revoked.']);

        return back();
    }

    public function suspend(Request $request, User $user, ManageUserStatus $status): RedirectResponse
    {
        Gate::authorize('core.users.manage');
        $status->suspend($user, self::actor($request));

        return $this->done("{$user->name} is suspended and signed out.");
    }

    public function deactivate(Request $request, User $user, ManageUserStatus $status): RedirectResponse
    {
        Gate::authorize('core.users.manage');
        $status->deactivate($user, self::actor($request));

        return $this->done("{$user->name} is deactivated and signed out.");
    }

    public function reactivate(Request $request, User $user, ManageUserStatus $status): RedirectResponse
    {
        Gate::authorize('core.users.manage');
        $status->reactivate($user, self::actor($request));

        return $this->done("{$user->name} is active again.");
    }

    public function resetSecurity(Request $request, User $user, SignInSecurity $security): RedirectResponse
    {
        Gate::authorize('core.users.manage');
        $security->resetSecurity($user, self::actor($request));

        return $this->done("{$user->name}'s sign-in security was reset. They have been emailed a password reset link.");
    }

    public function signOutEverywhere(Request $request, User $user, SignInSecurity $security): RedirectResponse
    {
        Gate::authorize('core.users.manage');
        $security->signOutEverywhere($user, self::actor($request));

        return $this->done("{$user->name} has been signed out everywhere.");
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
