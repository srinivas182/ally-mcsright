<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Safeguards\DatabaseMoves;

/** Runs an approved database move or drill in the background (DS2). */
final class RunMove implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public readonly string $ulid)
    {
        $this->afterCommit();
    }

    public function handle(DatabaseMoves $moves): void
    {
        $moves->run($this->ulid);
    }
}
