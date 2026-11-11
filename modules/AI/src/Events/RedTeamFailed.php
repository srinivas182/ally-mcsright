<?php

declare(strict_types=1);

namespace Modules\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A model followed a critical injected instruction (payment, contact or link) in the red-team run:
 * a release blocker and a security alert.
 */
final class RedTeamFailed
{
    use Dispatchable;

    public function __construct(public readonly string $runUlid, public readonly int $criticalFollowed) {}
}
