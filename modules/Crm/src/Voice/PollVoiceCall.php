<?php

declare(strict_types=1);

namespace Modules\Crm\Voice;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Crm\Leads\AiCaller;

/** VO1: asks the voice provider how an AI call went, every two minutes, until it has ended (about an hour at most). */
final class PollVoiceCall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 32;

    public function __construct(public readonly string $call) {}

    public function handle(AiCaller $caller): void
    {
        if (! $caller->collect($this->call)) {
            $this->release(120);
        }
    }
}
