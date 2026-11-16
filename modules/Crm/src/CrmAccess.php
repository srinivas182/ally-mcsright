<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Modules\Core\Access\Can;

/**
 * Who counts as a CRM manager: owners, staff without a role (as before), or anyone whose role
 * grants "Manage CRM settings" (specs/core/03, R1).
 */
final class CrmAccess
{
    public static function manager(User $user): bool
    {
        return Can::manage($user, 'crm.settings.manage');
    }
}
