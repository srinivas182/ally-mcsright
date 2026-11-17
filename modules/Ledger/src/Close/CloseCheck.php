<?php

declare(strict_types=1);

namespace Modules\Ledger\Close;

use Modules\Ledger\Models\LedgerPeriod;

/**
 * A month-end check. Modules register their own (Payouts adds trust, splits and payout-run checks), so
 * the Ledger never needs to know about them. Checks run inside the brokerage's tenant context.
 */
interface CloseCheck
{
    /** Short stable key, e.g. "books_balance". */
    public function key(): string;

    /** What this check looks at, in plain words, shown as a ticked line when it finds nothing. */
    public function label(): string;

    /** @return list<Finding> */
    public function run(LedgerPeriod $period): array;
}
