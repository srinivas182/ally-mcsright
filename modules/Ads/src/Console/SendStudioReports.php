<?php

declare(strict_types=1);

namespace Modules\Ads\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Studio\StudioMonthlyReport;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioResults;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/** On the 1st: last month's Studio report to everyone with a Studio package who posted (specs/grow/13, SA4). */
#[CrossTenantAccess('Send monthly Ally Studio reports for every brokerage')]
final class SendStudioReports extends Command
{
    protected $signature = 'ads:studio-reports';

    protected $description = 'Send last month\'s Ally Studio reports';

    public function handle(TenantContext $tenants): int
    {
        $month = CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m');
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $sent += (int) $tenants->runAs($tenant, function () use ($month): int {
                    $n = 0;
                    $start = CarbonImmutable::parse($month.'-01');
                    $ids = SocialPost::query()->whereBetween('posted_at', [$start, $start->endOfMonth()])->distinct()->pluck('user_id');
                    foreach (User::query()->whereIn('id', $ids)->get() as $user) {
                        if (app(StudioPlans::class)->current($user) === null) {
                            continue;
                        }
                        $report = app(StudioResults::class)->month($user, $month);
                        if ($report['totals']['posts'] > 0) {
                            $user->notify(new StudioMonthlyReport($report));
                            $n++;
                        }
                    }

                    return $n;
                });
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("{$sent} report(s) sent.");

        return self::SUCCESS;
    }
}
