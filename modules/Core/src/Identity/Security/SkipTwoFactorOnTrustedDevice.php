<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Security;

use App\Models\User;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Fortify's login step that sends 2FA users to the challenge, except on a trusted device.
 */
final class SkipTwoFactorOnTrustedDevice extends RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user instanceof User && app(TrustedDevices::class)->isTrusted($user, $request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
