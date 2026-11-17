<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Safeguards\BrokerageRestore;

/** Runs a brokerage restore in the background (DS1). Control-plane work: it switches to the brokerage itself. */
final class RunRestore implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly string $ulid)
    {
        $this->afterCommit();
    }

    public function handle(BrokerageRestore $service): void
    {
        $service->run($this->ulid);
    }
}
