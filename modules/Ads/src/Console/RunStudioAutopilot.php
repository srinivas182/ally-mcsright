<?php

declare(strict_types=1);

namespace Modules\Ads\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Ads\Studio\Autopilot;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/** Hourly: Ally Studio Autopilot notices moments and moves planned posts on, for everyone who switched it on (specs/grow/13). */
#[CrossTenantAccess('Run Ally Studio Autopilot for every brokerage')]
final class RunStudioAutopilot extends Command
{
    protected $signature = 'ads:studio-autopilot';

    protected $description = 'Plan, design and schedule Ally Studio posts';

    public function handle(TenantContext $tenants): int
    {
        $new = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $new += (int) $tenants->runAs($tenant, function (): int {
                    $n = 0;
                    $ids = DB::connection(Connections::cell())->table('studio_autopilot')->where('tenant_id', app(TenantContext::class)->id())->where('mode', '!=', 'off')->pluck('user_id');
                    foreach (User::query()->whereIn('id', $ids)->get() as $user) {
                        try {
                            $n += app(Autopilot::class)->runFor($user);
                        } catch (Throwable $e) {
                            report($e);
                        }
                    }

                    return $n;
                });
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("{$new} new moment(s).");

        return self::SUCCESS;
    }
}
