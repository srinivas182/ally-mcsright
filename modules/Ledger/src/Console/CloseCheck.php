<?php

declare(strict_types=1);

namespace Modules\Ledger\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Close\CloseChecks;
use Modules\Ledger\Enums\PeriodStatus;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Ledger\MonthNeedsAttention;

/**
 * Nightly month-end check (FH1, specs/finance/22): every finished month that is still open is checked, and
 * owners hear once about each new thing that must be fixed before it can close.
 */
final class CloseCheck extends Command
{
    protected $signature = 'ledger:close-check';

    protected $description = 'Run the month-end checklist for finished, open months';

    public function handle(TenantContext $tenants, CloseChecks $checks): int
    {
        $today = CarbonImmutable::today();
        $checked = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            $checked += $tenants->runAs($tenant, function () use ($checks, $today): int {
                $n = 0;
                foreach (LedgerPeriod::query()->where('status', PeriodStatus::Open)->whereDate('ends_on', '<', $today->toDateString())->orderBy('starts_on')->get() as $period) {
                    $checks->refresh($period);
                    $titles = $checks->claimUnnotifiedStops($period);
                    if ($titles !== []) {
                        Notification::send(User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get(),
                            new MonthNeedsAttention($period->starts_on->format('F Y'), $titles));
                    }
                    $n++;
                }

                return $n;
            });
        }
        $this->info("{$checked} month(s) checked.");

        return self::SUCCESS;
    }
}
