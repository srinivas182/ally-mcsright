<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Privacy\PrivacyRequests;
use Modules\Core\Privacy\Retention;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/** Every night (TR1, specs/core/18): removes data past its time, and reminds about privacy requests due soon. */
final class PrivacyDaily extends Command
{
    protected $signature = 'core:privacy-daily';

    protected $description = 'Remove data past its retention time and remind about privacy requests due soon';

    public function handle(TenantContext $tenants): int
    {
        $removed = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $removed += $tenants->runAs($tenant, function (): int {
                    app(PrivacyRequests::class)->remind();

                    return app(Retention::class)->run();
                });
            } catch (Throwable $e) {
                report($e); // one brokerage never stops the others
            }
        }
        $this->info("{$removed} record(s) removed.");

        return self::SUCCESS;
    }
}
