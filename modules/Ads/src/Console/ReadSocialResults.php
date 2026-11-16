<?php

declare(strict_types=1);

namespace Modules\Ads\Console;

use Illuminate\Console\Command;
use Modules\Ads\Studio\StudioResults;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/** Daily: reads each recent post's numbers from the networks that share them (specs/grow/13, SA4). */
#[CrossTenantAccess('Read social post results for every brokerage')]
final class ReadSocialResults extends Command
{
    protected $signature = 'ads:social-results';

    protected $description = 'Read the numbers for recent social posts';

    public function handle(TenantContext $tenants): int
    {
        $read = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $read += (int) $tenants->runAs($tenant, fn (): int => app(StudioResults::class)->refresh());
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("{$read} post(s) read.");

        return self::SUCCESS;
    }
}
