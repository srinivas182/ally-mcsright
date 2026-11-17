<?php

declare(strict_types=1);

namespace Modules\ListIq\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Photos;

/** Enhancing or staging one listing photo (LI1), checking back every 30 seconds while the service works. */
final class ProcessPhoto implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 40;

    public int $timeout = 240;

    public function __construct(public readonly int $photoId)
    {
        $this->afterCommit();
    }

    public function handle(Photos $photos): void
    {
        $photo = Photo::query()->find($this->photoId);
        if ($photo === null || in_array($photo->status, ['ready', 'failed'], true)) {
            return;
        }
        if (! $photos->step($photo) && $this->attempts() < $this->tries) {
            $this->release(30);
        } elseif (! in_array($photo->fresh()?->status, ['ready', 'failed'], true)) {
            $photos->fail($photo, 'It took too long. Please try again.');
        }
    }
}
