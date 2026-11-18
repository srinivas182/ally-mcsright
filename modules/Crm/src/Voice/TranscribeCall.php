<?php

declare(strict_types=1);

namespace Modules\Crm\Voice;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** VO1: writes down a recorded call and makes the agent's notes; asks again every minute while the provider works. */
final class TranscribeCall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 30;

    public function __construct(public readonly string $call) {}

    public function handle(RecordedCalls $calls): void
    {
        if (! $calls->transcribe($this->call)) {
            $this->release(60);
        }
    }
}
