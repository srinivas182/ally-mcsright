<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Tenancy\Actions\TenantLifecycle;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Events\TenantPurgeDue;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Daily: close tenants whose 30-day closing period has ended, and announce tenants whose purge
 * date has arrived (once each). No data is deleted here.
 */
final class RunTenantLifecycle extends Command
{
    protected $signature = 'tenants:lifecycle';

    protected $description = 'Advance tenants through closing and announce purge-due tenants';

    public function handle(TenantLifecycle $lifecycle): int
    {
        $now = CarbonImmutable::now();

        $closing = Tenant::query()
            ->where('status', TenantStatus::Closing)
            ->where('closing_at', '<=', $now->subDays(TenantLifecycle::CLOSING_DAYS))
            ->get();

        foreach ($closing as $tenant) {
            $lifecycle->finishClosing($tenant);
            $this->line("Closed {$tenant->slug}");
        }

        $purgeDue = Tenant::query()
            ->where('status', TenantStatus::Closed)
            ->where('purge_after', '<=', $now)
            ->whereNull('purge_due_notified_at')
            ->get();

        foreach ($purgeDue as $tenant) {
            $tenant->forceFill(['purge_due_notified_at' => $now])->save();
            TenantPurgeDue::dispatch($tenant->id);
            $this->line("Purge due: {$tenant->slug}");
        }

        $this->info("Closed {$closing->count()}, purge due {$purgeDue->count()}.");

        return self::SUCCESS;
    }
}
