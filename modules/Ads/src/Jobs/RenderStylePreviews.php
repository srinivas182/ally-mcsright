<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ads\Studio\StylePreviews;

/** Renders one person's previews of every Ally Studio style (specs/grow/13, SA1d). */
final class RenderStylePreviews implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $userId)
    {
        $this->afterCommit();
    }

    public function handle(StylePreviews $previews): void
    {
        $user = User::query()->find($this->userId);
        if ($user !== null) {
            $previews->make($user);
        }
    }
}
