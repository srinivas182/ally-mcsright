<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Auth;

use App\Models\User;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword;

/**
 * Password-reset tokens keyed by tenant + email (specs/core/02).
 */
final class TenantPasswordTokenRepository extends DatabaseTokenRepository
{
    public function create(CanResetPassword $user)
    {
        return parent::create($this->key($user));
    }

    public function exists(CanResetPassword $user, #[\SensitiveParameter] $token)
    {
        return parent::exists($this->key($user), $token);
    }

    public function recentlyCreatedToken(CanResetPassword $user)
    {
        return parent::recentlyCreatedToken($this->key($user));
    }

    public function delete(CanResetPassword $user)
    {
        parent::delete($this->key($user));
    }

    private function key(CanResetPassword $user): CanResetPassword
    {
        return $user instanceof User ? new TenantKeyedResetUser($user) : $user;
    }
}
