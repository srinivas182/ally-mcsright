<?php

declare(strict_types=1);

namespace Modules\Compensation\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A plan version was published, the default plan changed or an agent was moved to another plan:
 * pending deals of the affected agents are recalculated (null = all agents).
 */
final class PlansChanged
{
    use Dispatchable;

    /**
     * @param  list<int>|null  $agentUserIds
     */
    public function __construct(public readonly ?array $agentUserIds, public readonly string $reason) {}
}
