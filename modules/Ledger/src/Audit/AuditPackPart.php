<?php

declare(strict_types=1);

namespace Modules\Ledger\Audit;

use Carbon\CarbonImmutable;

/**
 * Files one module adds to the audit pack (specs/finance/23). Runs in the brokerage's tenant context.
 * Never include tax ID numbers, bank account numbers or passwords.
 */
interface AuditPackPart
{
    /**
     * @return array<string, string> file name in the pack => contents
     */
    public function files(CarbonImmutable $from, CarbonImmutable $to): array;
}
