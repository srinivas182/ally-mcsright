<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Events\UserDeactivated;
use Modules\Core\Identity\Events\UserReactivated;
use Modules\Core\Identity\Events\UserSuspended;

/**
 * Suspend, reactivate and deactivate users. Sessions end immediately; assistants of an agent who
 * is suspended or deactivated are suspended too. The last active owner is protected.
 */
final class ManageUserStatus
{
    public function suspend(User $user, User $by): User
    {
        $this->guard($user, $by, [UserStatus::Active]);
        $this->set($user, UserStatus::Suspended);
        UserSuspended::dispatch($user->tenant_id, $user->id, $by->id);
        $this->suspendAssistantsOf($user, $by);

        return $user;
    }

    public function deactivate(User $user, User $by): User
    {
        $this->guard($user, $by, [UserStatus::Active, UserStatus::Suspended]);
        $this->set($user, UserStatus::Deactivated);
        UserDeactivated::dispatch($user->tenant_id, $user->id, $by->id);
        $this->suspendAssistantsOf($user, $by);

        return $user;
    }

    public function reactivate(User $user, User $by): User
    {
        if ($user->is($by)) {
            throw ValidationException::withMessages(['user' => 'You cannot change your own status.']);
        }

        if (! in_array($user->status, [UserStatus::Suspended, UserStatus::Deactivated], true)) {
            throw ValidationException::withMessages(['user' => 'This user is already active.']);
        }

        $user->forceFill(['status' => UserStatus::Active])->save();
        UserReactivated::dispatch($user->tenant_id, $user->id, $by->id);

        return $user;
    }

    /**
     * @param  list<UserStatus>  $from
     */
    private function guard(User $user, User $by, array $from): void
    {
        if ($user->is($by)) {
            throw ValidationException::withMessages(['user' => 'You cannot change your own status.']);
        }

        if (! in_array($user->status, $from, true)) {
            throw ValidationException::withMessages(['user' => 'This change is not possible for a user who is '.$user->status->value.'.']);
        }

        if ($user->kind === UserKind::Owner) {
            $otherOwners = User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)
                ->whereKeyNot($user->id)->count();

            if ($otherOwners === 0) {
                throw ValidationException::withMessages(['user' => 'The brokerage needs at least one active owner.']);
            }
        }
    }

    private function set(User $user, UserStatus $status): void
    {
        $user->forceFill(['status' => $status])->save();

        // End database sessions now; other drivers are covered by the per-request check.
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    private function suspendAssistantsOf(User $agent, User $by): void
    {
        if ($agent->kind !== UserKind::Agent) {
            return;
        }

        User::query()->where('assisted_user_id', $agent->id)->where('status', UserStatus::Active)->get()
            ->each(function (User $assistant) use ($by): void {
                $this->set($assistant, UserStatus::Suspended);
                UserSuspended::dispatch($assistant->tenant_id, $assistant->id, $by->id);
            });
    }
}
