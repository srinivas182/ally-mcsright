<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Creates the first cell and the RightAlly HQ tenant on the HQ host. Safe to run repeatedly.
 */
final class BootstrapHq extends Command
{
    protected $signature = 'tenants:bootstrap-hq {--cell=cell-1 : Name of the first cell} {--region=us-east-1}';

    protected $description = 'Create the first cell and the RightAlly HQ tenant';

    public function handle(): int
    {
        $cell = Cell::query()->firstOrCreate(
            ['name' => (string) $this->option('cell')],
            ['region' => (string) $this->option('region'), 'status' => CellStatus::Active, 'db_connection' => 'cell'],
        );

        $hq = Tenant::query()->firstOrCreate(
            ['type' => TenantType::Hq],
            ['name' => 'RightAlly HQ', 'slug' => 'rightally-hq', 'status' => TenantStatus::Active, 'cell_id' => $cell->id],
        );

        $hq->domains()->firstOrCreate(
            ['host' => Host::normalise((string) config('core.hq_host'))],
            [
                'kind' => DomainKind::Custom,
                'is_primary' => true,
                'verification_status' => VerificationStatus::Verified,
                'ssl_status' => SslStatus::Active,
                'verified_at' => now(),
            ],
        );

        $this->info("HQ tenant ready on {$hq->primaryDomain()?->host} (cell {$cell->name}).");

        return self::SUCCESS;
    }
}
