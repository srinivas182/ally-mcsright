<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Modules\Core\Access\Permissions;
use Modules\Core\Identity\Enums\UserKind;

/**
 * Who counts as a CRM manager: owners and staff (as before), or anyone whose role grants
 * "Manage CRM settings" (specs/core/03).
 */
final class CrmAccess
{
    public static function manager(User $user): bool
    {
        return $user->isKind(UserKind::Owner, UserKind::Staff) || app(Permissions::class)->allows($user, 'crm.settings.manage');
    }
}
