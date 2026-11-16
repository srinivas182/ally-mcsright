<?php

declare(strict_types=1);

namespace Modules\Ads\Console;

use Illuminate\Console\Command;
use Modules\Ads\Studio\Voice\Narration;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/** Daily: agents' own AI voices are deleted when they leave or after 12 months unused (specs/grow/13, SA5b). */
#[CrossTenantAccess('Delete agents\' AI voices that are no longer allowed to be kept, for every brokerage')]
final class SweepStudioVoices extends Command
{
    protected $signature = 'ads:studio-voices';

    protected $description = 'Delete AI voices of agents who left or have not used them for 12 months';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += (int) $tenants->runAs($tenant, fn (): int => app(Narration::class)->sweep());
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} voice(s) deleted.");

        return self::SUCCESS;
    }
}
