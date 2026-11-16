<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ads\Studio\Reels;
use Modules\Ads\Studio\StudioVideo;

/** Makes one Reel or Short on a render worker (specs/grow/13, SA3). */
final class RenderReel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $videoId)
    {
        $this->afterCommit();
    }

    public function handle(Reels $reels): void
    {
        $video = StudioVideo::query()->find($this->videoId);
        if ($video !== null && $video->status === 'rendering') {
            $reels->render($video);
        }
    }
}
