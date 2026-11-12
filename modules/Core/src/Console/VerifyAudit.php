<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Audit\AuditVerifier;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Recomputes a brokerage's audit hash chain and reports the first broken or missing event.
 */
final class VerifyAudit extends Command
{
    protected $signature = 'audit:verify {tenant : Brokerage slug or ULID} {--from= : First sequence number} {--to= : Last sequence number}';

    protected $description = 'Verify the integrity of a brokerage audit log';

    public function handle(): int
    {
        $key = (string) $this->argument('tenant');
        $tenant = Tenant::query()->where('slug', $key)->orWhere('ulid', $key)->first();
        if ($tenant === null) {
            $this->error('Brokerage not found.');

            return self::FAILURE;
        }

        $result = app(AuditVerifier::class)->verify($tenant->id, $this->option('from') !== null ? (int) $this->option('from') : null, $this->option('to') !== null ? (int) $this->option('to') : null);
        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message'].' Brokerage: '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}
