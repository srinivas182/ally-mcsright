<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Modules\Transactions\Models\Deal;

/**
 * A check another module can add before a deal goes under contract (tagged "transactions.deal_guards").
 * Returns null to allow, or a plain-English reason to stop.
 */
interface DealGuard
{
    public function check(Deal $deal): ?string;
}
