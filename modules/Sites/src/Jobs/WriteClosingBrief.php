<?php

declare(strict_types=1);

namespace Modules\Sites\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sites\Visitors\ClosingBrief;

/** Writes (or refreshes) the closing brief on a website lead (specs/websites/12). */
final class WriteClosingBrief implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $visitorId, public readonly bool $force = false)
    {
        $this->afterCommit();
    }

    public function handle(ClosingBrief $brief): void
    {
        $brief->generate($this->visitorId, $this->force);
    }
}
