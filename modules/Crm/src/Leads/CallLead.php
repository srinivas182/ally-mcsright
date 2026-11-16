<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Places a Lead-IQ call (LQ1). The tenant is restored by the queue's tenant middleware. When it is too early or too
 * late where the person is, it tries again every 30 minutes, for up to 14 hours.
 */
final class CallLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 29;

    public function __construct(public readonly string $call) {}

    public function handle(AiCaller $caller): void
    {
        if (! $caller->place($this->call)) {
            $this->release(1800);
        }
    }
}
