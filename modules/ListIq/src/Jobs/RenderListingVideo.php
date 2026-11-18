<?php

declare(strict_types=1);

namespace Modules\ListIq\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\ListIq\ListingVideo;
use Modules\ListIq\Models\Prep;

/** MD1: makes a listing video on a render worker. */
final class RenderListingVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $prepId, public readonly int $userId)
    {
        $this->afterCommit();
    }

    public function handle(ListingVideo $video): void
    {
        $prep = Prep::query()->find($this->prepId);
        $user = User::query()->find($this->userId);
        if ($prep !== null && $user !== null && $prep->video_status === 'making') {
            $video->render($prep, $user);
        }
    }
}
