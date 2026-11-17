<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Safeguards\BrokerageExport;

/** Runs a brokerage export in the background (DS1). Control-plane work: it switches to the brokerage itself. */
final class RunExport implements ShouldQueue
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

    public function handle(BrokerageExport $service): void
    {
        $service->run($this->ulid);
    }
}
