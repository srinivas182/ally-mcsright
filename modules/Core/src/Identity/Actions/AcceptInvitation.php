<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Identity\Models\Invitation;

/**
 * Accepting an invitation creates an active, verified user in the invitation's tenant.
 */
final class AcceptInvitation
{
    public function handle(Invitation $invitation, string $firstName, string $lastName, #[\SensitiveParameter] string $password): User
    {
        if (! $invitation->isOpen()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid.']);
        }

        $user = DB::transaction(function () use ($invitation, $firstName, $lastName, $password): User {
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'An account with this email already exists here. Please sign in.']);
            }

            $user = new User([
                'kind' => $invitation->kind,
                'first_name' => trim($firstName),
                'last_name' => trim($lastName),
                'email' => $invitation->email,
                'password' => $password,
                'status' => UserStatus::Active,
                'assisted_user_id' => $invitation->assisted_user_id,
                'created_by' => $invitation->invited_by,
            ]);
            $user->email_verified_at = now();
            $user->save();

            $invitation->forceFill(['accepted_at' => CarbonImmutable::now()])->save();

            return $user;
        });

        UserActivated::dispatch($user->tenant_id, $user->id, $invitation->invited_by);

        return $user;
    }
}
