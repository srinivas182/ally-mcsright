<?php

declare(strict_types=1);

namespace Modules\Sites\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sites\Visitors\JourneySummary;

/** Writes (or refreshes) the journey summary the agent sees on the lead (specs/websites/07). */
final class SummariseJourney implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public readonly int $visitorId)
    {
        $this->afterCommit();
    }

    public function handle(JourneySummary $journeys): void
    {
        $journeys->summarise($this->visitorId);
    }
}
