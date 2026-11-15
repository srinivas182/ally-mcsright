<?php

declare(strict_types=1);

namespace Modules\Connect\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;

/** Syncs one connection; only one queued and one running per connection at a time (specs/connect/01). */
final class SyncConnection implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

    /** @return list<object> one run per connection at a time (a long import can queue its next step) */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('connect-sync-'.$this->connectionId))->releaseAfter(60)->expireAfter(900)];
    }

    public function handle(SyncRunner $runner): void
    {
        $c = Connection::query()->find($this->connectionId);
        if ($c !== null) {
            $runner->run($c);
        }
    }
}
