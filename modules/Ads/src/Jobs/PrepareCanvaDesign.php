<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ads\Studio\Canva\CanvaSession;
use Modules\Ads\Studio\Canva\CanvaStudio;

/** Canva in Ally Studio (specs/grow/13, C7d-3): one step of a trip to Canva and back. A failure is shown, not retried. */
final class PrepareCanvaDesign implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $sessionId)
    {
        $this->afterCommit();
    }

    public function handle(CanvaStudio $canva): void
    {
        $s = CanvaSession::query()->find($this->sessionId);
        if ($s !== null) {
            $canva->prepare($s);
        }
    }
}
