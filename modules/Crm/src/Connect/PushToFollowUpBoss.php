<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/** Sends one RightAlly contact or note to Follow Up Boss (specs/connect/05); safe to repeat. */
final class PushToFollowUpBoss implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $connectionId, public readonly string $entity, public readonly int $localId)
    {
        $this->afterCommit();
    }

    /** @return list<object> one send per contact at a time (a note may first add its person) */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('fub-push-'.$this->connectionId))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(ConnectorKit $kit, FollowUpBoss $driver): void
    {
        $c = $kit->connection($this->connectionId);
        if ($c === null || ! in_array($this->entity, ['contact', 'note'], true)) {
            return;
        }
        try {
            $driver->push($c, $this->entity, $this->localId);
        } catch (NeedsReconnect) {
            // The connection's health check asks the owner to connect again; nothing to retry.
        }
    }
}
