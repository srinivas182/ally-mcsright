<?php

declare(strict_types=1);

namespace Modules\Transactions\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A deal's money and wire blockers are all cleared; a payout that waited on them can go ahead. */
final class PayoutHoldCleared
{
    use Dispatchable;

    public function __construct(public readonly int $dealId, public readonly int $waitingBy) {}
}
