<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\GoLive\GoLive;
use Modules\Billing\GoLive\GoLiveWeekMail;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Mondays: each brokerage in its first five weeks gets its go-live progress and next steps (specs/billing/07). */
#[CrossTenantAccess('Sends each new brokerage its own go-live progress')]
final class GoLiveWeekly extends Command
{
    protected $signature = 'billing:go-live-weekly';

    protected $description = 'Email new brokerages their go-live progress and next steps';

    public function handle(TenantContext $tenants): int
    {
        $db = DB::connection(Connections::control());
        $sent = 0;
        foreach ($db->table('go_live_plans')->where('started_on', '>=', now()->subDays(35)->toDateString())->get() as $row) {
            if ($row->last_mailed_at !== null && now()->subDays(6)->lt($row->last_mailed_at)) {
                continue;
            }
            $tenant = Tenant::query()->where('status', 'active')->find((int) $row->tenant_id);
            if ($tenant === null) {
                continue;
            }
            $sent += (int) $tenants->runAs($tenant, function () use ($tenant, $row): bool {
                $plan = app(GoLive::class)->plan();
                $owners = User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get();
                if ($plan === null || $owners->isEmpty()) {
                    return false;
                }
                $next = array_slice(array_map(fn (array $s): string => $s['title'], array_values(array_filter($plan['steps'], fn (array $s): bool => ! $s['done']))), 0, 3);
                Notification::send($owners, new GoLiveWeekMail($plan['day'], $plan['done'], $plan['total'], $next, 'https://'.Host::platformHostFor($tenant->slug).'/switch', $row->coach_name));

                return true;
            });
            $db->table('go_live_plans')->where('id', $row->id)->update(['last_mailed_at' => now()]);
        }
        $this->info("{$sent} go-live email(s) sent.");

        return self::SUCCESS;
    }
}
