<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Who must use a second factor (specs/core/02, Sprint 4A review; SX3). A passkey counts as a second factor (it is
 * phishing-resistant on its own) and is offered first. No text-message codes are offered.
 *
 * - Right away: HQ staff, brokerage owners, anyone with finance access, and everyone when the brokerage turns on
 *   "require two-step sign-in now".
 * - Everyone else (agents, staff, assistants): after a grace period of GRACE_DAYS from the first
 *   time they are checked, with a reminder on every page until then. The grace is a default flagged for review.
 */
final class TwoFactorPolicy
{
    public const GRACE_DAYS = 14;

    /** Whether the second factor is needed today, with no grace. */
    public function requiresNow(User $user): bool
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

    /**
     * When the person must have a second factor; null when they already must (no grace). Starts the grace period
     * the first time it is asked.
     */
    public function dueAt(User $user): ?CarbonImmutable
    {
        if ($this->requiresNow($user)) {
            return null;
        }
        $due = $user->second_factor_due_at;
        if ($due === null) {
            $due = CarbonImmutable::now()->addDays(self::GRACE_DAYS);
            $user->forceFill(['second_factor_due_at' => $due])->saveQuietly();
        }

        return CarbonImmutable::instance($due);
    }

    /** Must set one up before doing anything else: needed, missing, and the grace (if any) is over. */
    public function mustSetUpNow(User $user): bool
    {
        if ($this->satisfied($user)) {
            return false;
        }
        $due = $this->dueAt($user);

        return $due === null || ! $due->isFuture();
    }

    /** Whole days left to set one up (at least 1 on the last day); null when nothing is due or it is overdue. */
    public function daysLeft(User $user): ?int
    {
        if ($this->satisfied($user)) {
            return null;
        }
        $due = $this->dueAt($user);
        if ($due === null || ! $due->isFuture()) {
            return null;
        }

        return max(1, (int) ceil(CarbonImmutable::now()->diffInHours($due) / 24));
    }
}
