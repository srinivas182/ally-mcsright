<?php

declare(strict_types=1);

namespace Modules\Connect\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;

/** Syncs one connection; only one run per connection at a time (specs/connect/01). */
final class SyncConnection implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $connectionId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'connect-sync-'.$this->connectionId;
    }

    public function handle(SyncRunner $runner): void
    {
        $c = Connection::query()->find($this->connectionId);
        if ($c !== null) {
            $runner->run($c);
        }
    }
}
