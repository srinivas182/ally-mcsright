<?php

declare(strict_types=1);

namespace Modules\Sites\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sites\Ailly\BookingTexts;

/** The reminder text two hours before a time booked through AIlly (specs/websites/14). */
final class RemindBooking implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $taskId, public readonly string $assistant)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        BookingTexts::remind($this->taskId, $this->assistant);
    }
}
