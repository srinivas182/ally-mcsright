<?php

declare(strict_types=1);

namespace Modules\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A batch finished; the feature that submitted it reads its results with AiBatch::resultFor().
 */
final class AiBatchCompleted
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly string $batchUlid, public readonly string $featureKey) {}
}
