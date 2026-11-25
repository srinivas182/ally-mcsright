<?php

declare(strict_types=1);

namespace Modules\Transactions\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Mls\MlsListings;
use Throwable;

/** ML2: nightly, after the MLS IDs are matched: prices of listings on the market kept in step; new ones added when the owner chose automatic. */
#[CrossTenantAccess('Bring every brokerage\'s agents\' MLS listings into My listings')]
final class SyncMlsListings extends Command
{
    protected $signature = 'transactions:mls-listings';

    protected $description = "Keep agents' MLS listings in My listings in step with the MLS";

    public function handle(TenantContext $tenants): int
    {
        $added = 0;
        $repriced = 0;
        foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
            try {
                $tenants->runAs($tenant, function () use (&$added, &$repriced): void {
                    // Changes made by the nightly sync are recorded in the owner's name, as the brokerage chose them.
                    $owner = User::query()->where('kind', UserKind::Owner)->orderBy('id')->first();
                    if ($owner === null) {
                        return;
                    }
                    $result = app(MlsListings::class)->sync($owner);
                    $added += $result['added'];
                    $repriced += $result['repriced'];
                });
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("MLS listings: {$added} added, {$repriced} price(s) updated.");

        return self::SUCCESS;
    }
}
