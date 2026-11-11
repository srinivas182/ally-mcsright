<?php

declare(strict_types=1);

namespace Modules\Core\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Notifications\ConfirmNewEmail;
use Modules\Core\Identity\Notifications\EmailChanged;
use Modules\Core\Identity\Notifications\EmailChangeRequested;

/**
 * Changing the sign-in email (specs/core/02, 4A-2): the new address must be confirmed within an
 * hour before it takes effect, and the old address is told at both steps, so a stolen session
 * cannot quietly move the account to an attacker's email.
 */
final class EmailChange
{
    public function request(User $user, string $newEmail): void
    {
        $newEmail = mb_strtolower(trim($newEmail));
        if ($newEmail === mb_strtolower($user->email)) {
            return;
        }
        if (User::query()->where('email', $newEmail)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Someone in this brokerage already uses that email.']);
        }

        $token = Str::random(48);
        $user->forceFill([
            'pending_email' => $newEmail,
            'pending_email_token' => hash('sha256', $token),
            'pending_email_expires_at' => CarbonImmutable::now()->addHour(),
        ])->save();

        $url = URL::temporarySignedRoute('email-change.confirm', CarbonImmutable::now()->addHour(), ['token' => $token]);
        Notification::route('mail', $newEmail)->notify(new ConfirmNewEmail($user->first_name, $url));
        $user->notify(new EmailChangeRequested($newEmail));
    }

    public function confirm(User $user, string $token): void
    {
        $valid = $user->pending_email !== null && $user->pending_email_token !== null
            && hash_equals($user->pending_email_token, hash('sha256', $token))
            && $user->pending_email_expires_at !== null && $user->pending_email_expires_at->isFuture();
        if (! $valid) {
            throw ValidationException::withMessages(['email' => 'That confirmation link has expired. Please change your email again.']);
        }
        if (User::query()->where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Someone in this brokerage already uses that email.']);
        }

        $old = $user->email;
        $user->forceFill([
            'email' => $user->pending_email, 'email_verified_at' => CarbonImmutable::now(),
            'pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null,
        ])->save();

        Notification::route('mail', $old)->notify(new EmailChanged((string) $user->email));
    }
}
