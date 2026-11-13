<?php

declare(strict_types=1);

namespace Modules\Payouts\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Payouts\TrustOverdue;
use Modules\Payouts\TrustReconciliation;

/** On the 10th: owners are reminded if last month's trust account is not reconciled and approved. */
final class TrustReminder extends Command
{
    protected $signature = 'payouts:trust-reminder';

    protected $description = 'Remind owners about unreconciled trust accounts';

    public function handle(TenantContext $tenants): int
    {
        $today = CarbonImmutable::today();
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            $sent += $tenants->runAs($tenant, function () use ($today): int {
                if (! app(TrustReconciliation::class)->overdue($today)) {
                    return 0;
                }
                Notification::send(User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get(), new TrustOverdue($today->subMonthNoOverflow()->format('F Y')));

                return 1;
            });
        }
        $this->info("{$sent} brokerage(s) reminded.");

        return self::SUCCESS;
    }
}
