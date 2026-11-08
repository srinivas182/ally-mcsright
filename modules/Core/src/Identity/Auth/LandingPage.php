<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Auth;

use App\Models\User;
use Modules\Core\Identity\Enums\UserKind;

/**
 * Where each kind of user lands after signing in (specs/core/02).
 */
final class LandingPage
{
    public static function for(User $user): string
    {
        return match ($user->kind) {
            UserKind::HqStaff => '/hq/tenants',
            UserKind::Owner, UserKind::Staff => '/dashboard',
            UserKind::Agent, UserKind::Assistant => '/agent',
        };
    }
}
