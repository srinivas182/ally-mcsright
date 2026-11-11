<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Modules\Core\Identity\Models\TrustedDevice;

/**
 * "Trust this device for 30 days": skip the 2FA prompt on a device the user already verified.
 * The cookie holds a random token (encrypted by Laravel); only its hash is stored.
 */
final class TrustedDevices
{
    public const COOKIE = 'ra_trusted_device';

    public const DAYS = 30;

    public function trust(User $user, Request $request): void
    {
        $token = Str::random(48);
        TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'label' => DeviceLabel::from($request->userAgent()),
            'ip' => $request->ip(),
            'last_used_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(self::DAYS),
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $user->id.'|'.$token, self::DAYS * 24 * 60, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
    }

    public function isTrusted(User $user, Request $request): bool
    {
        $cookie = $request->cookie(self::COOKIE);
        $value = is_string($cookie) ? $cookie : '';
        [$userId, $token] = array_pad(explode('|', $value, 2), 2, '');

        if ((int) $userId !== $user->id || $token === '') {
            return false;
        }

        $device = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', CarbonImmutable::now())
            ->first();

        $device?->update(['last_used_at' => CarbonImmutable::now()]);

        return $device !== null;
    }

    public function revokeAll(User $user): void
    {
        TrustedDevice::query()->where('user_id', $user->id)->delete();
    }
}
