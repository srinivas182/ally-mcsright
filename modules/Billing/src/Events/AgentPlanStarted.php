<?php

declare(strict_types=1);

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** CM4: an agent's paid plan (pack) has started; dispatched inside the brokerage's tenant. */
final class AgentPlanStarted
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $userId) {}
}
