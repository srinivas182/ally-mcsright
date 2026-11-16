<?php

declare(strict_types=1);

namespace Modules\Ads\Console;

use Illuminate\Console\Command;
use Modules\Ads\Lifecycle;
use Modules\Ads\ListingAds;
use Modules\Ads\Models\Campaign;
use Modules\Ads\Networks;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/** Hourly: results, stop-loss, end dates and refunds for RightAlly Boosts (own-account ads refresh with their connection). */
final class RefreshBoosts extends Command
{
    protected $signature = 'ads:refresh-boosts';

    protected $description = 'Refresh RightAlly Boost results, apply the stop-loss, end finished boosts and refund unspent credits';

    public function handle(TenantContext $tenants, ListingAds $ads, Lifecycle $lifecycle): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $n += $tenants->runAs($tenant, function () use ($ads, $lifecycle): int {
                $count = 0;
                foreach (Campaign::query()->where('billing', 'boost')->whereIn('status', ['active', 'paused'])->get() as $c) {
                    try {
                        if ($c->status === 'paused' && $c->ends_on->endOfDay()->isPast()) {
                            $lifecycle->end($c, 'Finished on its end date.');
                        } elseif (($results = Networks::for($c)->results($c, $token = $ads->token($c))) !== null) {
                            $lifecycle->apply($c, $results, $token);
                        }
                        $count++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }

                return $count;
            });
        }
        $this->info("{$n} boost(s) refreshed.");

        return self::SUCCESS;
    }
}
