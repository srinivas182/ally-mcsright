<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Modules\Core\Access\Can;
use Modules\Sites\Models\Site;

/**
 * Who may edit a website (specs/websites/08, 09): owners and staff (and roles that manage CRM settings) edit every
 * website of the brokerage; an agent edits their own website's sections when the brand kit allows it.
 */
final class SiteAccess
{
    /**
     * Who manages the brokerage's websites, AIlly, funnels and reviews (specs/core/03, R1): owners, staff without a role,
     * or a role with "Manage the brokerage website".
     */
    public static function manager(User $user): bool
    {
        return Can::manage($user, 'sites.manage');
    }

    public static function canEdit(User $user, Site $site): bool
    {
        if (self::manager($user)) {
            return true;
        }
        if ($site->kind !== 'agent' || $site->user_id !== $user->id) {
            return false;
        }
        $rules = AgentSites::rules();

        return $rules['enabled'] && $rules['sections'];
    }
}
