<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;

/** Renders one Ally Studio design in its three sizes on a render worker (specs/grow/13). */
final class RenderDesign implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $designId)
    {
        $this->afterCommit();
    }

    public function handle(Designer $designer): void
    {
        $design = Design::query()->find($this->designId);
        if ($design !== null && $design->status === 'rendering') {
            $designer->render($design);
        }
    }
}
