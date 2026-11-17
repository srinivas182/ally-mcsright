<?php

declare(strict_types=1);

namespace Modules\Engage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Crm\Models\Activity;
use Modules\Engage\DatabaseAssistant;
use Modules\Engage\Models\CheckIn;
use Throwable;

/** Reads a reply to a check-in in the background (EN1, D23). If AI is unavailable the agent still has the usual Inbox alert. */
final class ReadCheckInReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly int $checkInId, public readonly int $activityId)
    {
        $this->afterCommit();
    }

    public function handle(DatabaseAssistant $assistant): void
    {
        $c = CheckIn::query()->whereKey($this->checkInId)->first();
        $a = Activity::query()->whereKey($this->activityId)->first();
        if ($c === null || $a === null) {
            return;
        }
        try {
            $assistant->readReply($c, $a);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
