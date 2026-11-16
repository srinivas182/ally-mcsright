<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use App\Models\User;

/**
 * One manager check for every module (specs/core/03, R1). Owners always pass. Staff with no role keep
 * the access of their user type. Anyone whose access is decided by roles needs the permission.
 */
final class Can
{
    public static function manage(User $user, string $permission): bool
    {
        return app(Permissions::class)->manages($user, $permission);
    }
}
