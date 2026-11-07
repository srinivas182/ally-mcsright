<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Actions;

use Carbon\CarbonImmutable;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Events\TenantClosed;
use Modules\Core\Tenancy\Events\TenantClosing;
use Modules\Core\Tenancy\Events\TenantReactivated;
use Modules\Core\Tenancy\Events\TenantSuspended;
use Modules\Core\Tenancy\Exceptions\InvalidTenantTransition;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Suspend, reactivate and close tenants (specs/core/01). Data is never touched here: suspension
 * blocks use, closing starts a reversible 30-day period, then the tenant is closed and kept
 * until purge_after (90 days later).
 *
 *   active ──suspend──▶ suspended ──reactivate──▶ active
 *   active|suspended ──close──▶ closing ──(30 days)──▶ closed ──(90 days)──▶ purge due
 *   closing ──reactivate──▶ active
 */
final class TenantLifecycle
{
    public const CLOSING_DAYS = 30;

    public const PURGE_AFTER_DAYS = 90;

    public function suspend(Tenant $tenant, string $reason, ?int $byUserId = null): Tenant
    {
        $this->guardNotHq($tenant);
        $this->guardFrom($tenant, [TenantStatus::Active], 'suspend');
        $reason = $this->requireReason($reason);

        $tenant->forceFill(['status' => TenantStatus::Suspended, 'suspended_reason' => $reason])->save();
        TenantSuspended::dispatch($tenant->id, $reason, $byUserId);

        return $tenant;
    }

    public function reactivate(Tenant $tenant, ?int $byUserId = null): Tenant
    {
        $this->guardFrom($tenant, [TenantStatus::Suspended, TenantStatus::Closing], 'reactivate');

        $tenant->forceFill([
            'status' => TenantStatus::Active,
            'suspended_reason' => null,
            'closing_at' => null,
            'closing_reason' => null,
        ])->save();
        TenantReactivated::dispatch($tenant->id, $byUserId);

        return $tenant;
    }

    public function close(Tenant $tenant, string $reason, ?int $byUserId = null): Tenant
    {
        $this->guardNotHq($tenant);
        $this->guardFrom($tenant, [TenantStatus::Active, TenantStatus::Suspended], 'close');
        $reason = $this->requireReason($reason);

        $tenant->forceFill([
            'status' => TenantStatus::Closing,
            'closing_at' => CarbonImmutable::now(),
            'closing_reason' => $reason,
        ])->save();
        TenantClosing::dispatch($tenant->id, $reason, $byUserId);

        return $tenant;
    }

    /**
     * Called by the daily lifecycle command once the closing period has passed.
     */
    public function finishClosing(Tenant $tenant): Tenant
    {
        $this->guardFrom($tenant, [TenantStatus::Closing], 'finish closing');

        $now = CarbonImmutable::now();
        $tenant->forceFill([
            'status' => TenantStatus::Closed,
            'closed_at' => $now,
            'purge_after' => $now->addDays(self::PURGE_AFTER_DAYS),
        ])->save();
        TenantClosed::dispatch($tenant->id);

        return $tenant;
    }

    /**
     * @param  list<TenantStatus>  $allowed
     */
    private function guardFrom(Tenant $tenant, array $allowed, string $action): void
    {
        if (! in_array($tenant->status, $allowed, true)) {
            throw new InvalidTenantTransition("Cannot {$action} a tenant that is {$tenant->status->value}.");
        }
    }

    private function guardNotHq(Tenant $tenant): void
    {
        if ($tenant->isHq()) {
            throw new InvalidTenantTransition('RightAlly HQ cannot be suspended or closed.');
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidTenantTransition('A reason is required.');
        }

        return mb_substr($reason, 0, 255);
    }
}
