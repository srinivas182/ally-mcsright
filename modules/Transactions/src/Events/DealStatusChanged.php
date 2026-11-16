<?php

declare(strict_types=1);

namespace Modules\Transactions\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A deal was created or moved to another status, here or by a sync from a connected transaction app. Other modules
 * follow it: Movewise moves the introduction's status from the deal (specs/movewise/09).
 */
final class DealStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $dealId, public readonly ?string $from, public readonly string $to) {}
}
