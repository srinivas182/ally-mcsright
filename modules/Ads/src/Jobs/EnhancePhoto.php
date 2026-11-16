<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ads\Studio\StudioPhoto;
use Modules\Ads\Studio\StudioPhotos;

/**
 * Moves one photo enhancement on (specs/grow/13, SA5a): start it, then check back every 30 seconds until the service
 * has finished (up to 20 minutes), then keep the result. A twilight waits for its enhanced photo first.
 */
final class EnhancePhoto implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 45;

    public int $timeout = 240;

    public function __construct(public readonly int $photoId)
    {
        $this->afterCommit();
    }

    public function handle(StudioPhotos $photos): void
    {
        $photo = StudioPhoto::query()->find($this->photoId);
        if ($photo === null || in_array($photo->status, ['ready', 'failed'], true)) {
            return;
        }
        if (! $photos->step($photo) && $this->attempts() < $this->tries) {
            $this->release(30);
        } elseif (! in_array($photo->fresh()?->status, ['ready', 'failed'], true)) {
            $photos->fail($photo, 'The enhancement took too long. Please try again.');
        }
    }
}
