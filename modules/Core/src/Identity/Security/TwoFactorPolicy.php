<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Security;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Who must use a second factor (specs/core/02, Sprint 4A review): HQ staff, brokerage owners and
 * anyone with finance access always; everyone else when the brokerage requires it. A passkey counts
 * as a second factor (it is phishing-resistant on its own). No text-message codes are offered.
 */
final class TwoFactorPolicy
{
    public function requires(User $user): bool
    {
        if ($user->isKind(UserKind::HqStaff, UserKind::Owner)) {
            return true;
        }

        if (Gate::forUser($user)->allows('finance.ledger.view')) {
            return true;
        }

        return (bool) Tenant::query()->whereKey($user->tenant_id)->value('require_two_factor');
    }

    public function satisfied(User $user): bool
    {
        return ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null)
            || $user->passkeys()->exists();
    }
}
