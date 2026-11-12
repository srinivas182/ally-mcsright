<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditChain;
use Modules\Core\Support\Connections;
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

        $db = DB::connection(Connections::cell());
        $from = max(1, (int) ($this->option('from') ?? 1));
        $prev = $from === 1 ? AuditChain::GENESIS : (string) $db->table('audit_events')->where('tenant_id', $tenant->id)->where('chain_seq', $from - 1)->value('hash');
        $expected = $from;
        $checked = 0;

        $query = $db->table('audit_events')->where('tenant_id', $tenant->id)->where('chain_seq', '>=', $from)->orderBy('chain_seq');
        if ($this->option('to') !== null) {
            $query->where('chain_seq', '<=', (int) $this->option('to'));
        }

        foreach ($query->lazy(500) as $row) {
            $event = (array) $row;
            if ((int) $event['chain_seq'] !== $expected) {
                $this->error("Broken at sequence {$expected}: event missing.");

                return self::FAILURE;
            }
            if ($event['prev_hash'] !== $prev || AuditChain::hash($prev, $event) !== $event['hash']) {
                $this->error("Broken at sequence {$expected}: event {$event['ulid']} was altered.");

                return self::FAILURE;
            }
            $prev = (string) $event['hash'];
            $expected++;
            $checked++;
        }

        $this->info("Audit log intact: {$checked} event(s) verified for {$tenant->slug}.");

        return self::SUCCESS;
    }
}
