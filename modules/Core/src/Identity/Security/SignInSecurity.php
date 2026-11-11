<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Events\UserSecurityReset;
use Modules\Core\Identity\Events\UserSignedOutEverywhere;
use Modules\Core\Identity\Models\KnownDevice;
use Modules\Core\Identity\Notifications\NewSignInNotification;
use Modules\Core\Identity\Notifications\SecurityResetNotification;

/**
 * Sessions, sign-out, owner-assisted security reset and new-device alerts (specs/core/02, 4A).
 */
final class SignInSecurity
{
    public function __construct(private readonly TrustedDevices $trusted) {}

    /**
     * @return list<array{id: string, label: string, ip: string|null, last_active: string, current: bool}>
     */
    public function sessions(User $user, ?string $currentId): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        /** @var list<array{id: string, label: string, ip: string|null, last_active: string, current: bool}> */
        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'label' => DeviceLabel::from($row->user_agent),
                'ip' => $row->ip_address !== null ? (string) $row->ip_address : null,
                'last_active' => CarbonImmutable::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                'current' => $row->id === $currentId,
            ])->values()->all();
    }

    public function endSession(User $user, string $sessionId): void
    {
        DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', $sessionId)->delete();
    }

    public function signOutOthers(User $user, ?string $currentId): void
    {
        DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))->delete();
        $this->trusted->revokeAll($user);
    }

    public function signOutEverywhere(User $user, ?User $by = null): void
    {
        DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        $this->trusted->revokeAll($user);
        $user->forceFill(['remember_token' => null])->save();
        UserSignedOutEverywhere::dispatch($user->tenant_id, $user->id, $by?->id);
    }

    /**
     * Owner-assisted recovery for a locked-out user: removes 2FA and passkeys, signs out
     * everywhere and emails a password reset link. The user must set up 2FA again at next
     * sign-in if it is required. An email link alone can never bypass a second factor.
     */
    public function resetSecurity(User $user, User $by): void
    {
        if ($user->is($by)) {
            throw ValidationException::withMessages(['user' => 'Ask another owner to reset your sign-in security.']);
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
            $user->passkeys()->delete();
        });

        $this->signOutEverywhere($user, $by);
        Password::broker()->sendResetLink(['email' => $user->email]);
        $user->notify(new SecurityResetNotification($by->name));
        UserSecurityReset::dispatch($user->tenant_id, $user->id, $by->id);
    }

    /**
     * Remembers the device; emails an alert when a user signs in from a device not seen before.
     */
    public function recordSignIn(User $user, Request $request): void
    {
        $fingerprint = DeviceLabel::fingerprint($user->id, $request->userAgent());
        $known = KnownDevice::query()->where('user_id', $user->id)->where('fingerprint', $fingerprint)->first();

        if ($known !== null) {
            $known->update(['last_seen_at' => CarbonImmutable::now()]);

            return;
        }

        $isFirstDevice = ! KnownDevice::query()->where('user_id', $user->id)->exists();
        $label = DeviceLabel::from($request->userAgent());
        KnownDevice::query()->create(['user_id' => $user->id, 'fingerprint' => $fingerprint, 'label' => $label,
            'first_seen_at' => CarbonImmutable::now(), 'last_seen_at' => CarbonImmutable::now()]);

        if (! $isFirstDevice) {
            $secureUrl = URL::temporarySignedRoute('security.secure-account', CarbonImmutable::now()->addDay(), ['user' => $user->ulid]);
            $user->notify(new NewSignInNotification($label, (string) $request->ip(), $secureUrl));
        }
    }
}
