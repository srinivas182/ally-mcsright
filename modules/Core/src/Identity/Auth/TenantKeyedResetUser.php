<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\CanResetPassword;

/**
 * Presents a user to the password-token repository under a "{tenant}|{email}" key, so the same
 * email in two tenants never shares (or overwrites) a reset token.
 */
final readonly class TenantKeyedResetUser implements CanResetPassword
{
    public function __construct(private User $user) {}

    public function getEmailForPasswordReset(): string
    {
        return $this->user->tenant_id.'|'.$this->user->getEmailForPasswordReset();
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->user->sendPasswordResetNotification($token);
    }
}
