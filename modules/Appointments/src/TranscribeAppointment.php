<?php

declare(strict_types=1);

namespace Modules\Appointments;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** VO2: writes down a recorded appointment and makes the notes and coaching; asks again every minute while the provider works. */
final class TranscribeAppointment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 60;

    public int $timeout = 600;

    public function __construct(public readonly string $recording) {}

    public function handle(Recordings $recordings): void
    {
        if (! $recordings->transcribe($this->recording)) {
            $this->release(60);
        }
    }
}
