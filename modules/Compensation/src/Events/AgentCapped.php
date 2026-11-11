<?php

declare(strict_types=1);

namespace Modules\Compensation\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agent reached their cap (Learn-IQ and broker voice messages celebrate it).
 */
final class AgentCapped
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $agentUserId, public readonly string $capPeriodUlid) {}
}
