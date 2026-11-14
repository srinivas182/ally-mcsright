<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Modules\Crm\CrmAccess;
use Modules\Sites\Models\Site;

/**
 * Who may edit a website (specs/websites/08, 09): owners and staff (and roles that manage CRM settings) edit every
 * website of the brokerage; an agent edits their own website's sections when the brand kit allows it.
 */
final class SiteAccess
{
    public static function canEdit(User $user, Site $site): bool
    {
        if (CrmAccess::manager($user)) {
            return true;
        }
        if ($site->kind !== 'agent' || $site->user_id !== $user->id) {
            return false;
        }
        $rules = AgentSites::rules();

        return $rules['enabled'] && $rules['sections'];
    }
}
