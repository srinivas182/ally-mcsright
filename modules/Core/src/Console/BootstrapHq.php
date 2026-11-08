<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Creates the first cell and the RightAlly HQ tenant on the HQ host, and optionally invites the
 * first HQ staff member (no passwords on the command line). Safe to run repeatedly.
 */
final class BootstrapHq extends Command
{
    protected $signature = 'tenants:bootstrap-hq
        {--cell=cell-1 : Name of the first cell}
        {--region=us-east-1}
        {--admin-email= : Invite this person as the first HQ staff member}
        {--admin-first-name=RightAlly}
        {--admin-last-name=Admin}';

    protected $description = 'Create the first cell and the RightAlly HQ tenant, and invite the first HQ staff member';

    public function handle(TenantContext $tenants, ManageInvitations $invitations): int
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

        $email = (string) $this->option('admin-email');
        if ($email !== '') {
            $tenants->runAs($hq, fn () => $invitations->invite(
                $email, (string) $this->option('admin-first-name'), (string) $this->option('admin-last-name'), UserKind::HqStaff, null,
            ));
            $this->info("Invitation emailed to {$email}. It expires in ".ManageInvitations::VALID_DAYS.' days.');
        }

        return self::SUCCESS;
    }
}
