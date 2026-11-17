<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

use App\Models\User;

/**
 * The person's email signature (MK2, specs/crm/21): added to every email they send from RightAlly, and offered as
 * HTML to paste into Gmail or Outlook. The Ads module builds it from the brand kit; without it, a plain one.
 */
interface EmailSignature
{
    public function text(User $user): string;

    public function html(User $user): string;
}
