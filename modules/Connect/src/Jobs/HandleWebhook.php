<?php

declare(strict_types=1);

namespace Modules\Connect\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Connect\Health;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\SyncRun;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Connect\TwoWay;

/** One webhook from a connected app, handled by its driver and logged like a sync run (specs/connect/05). */
final class HandleWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  array<string, mixed>  $payload */
    public function __construct(public readonly int $connectionId, public readonly array $payload) {}

    public function handle(AppCatalog $catalog, Health $health): void
    {
        $c = Connection::query()->find($this->connectionId);
        $app = $c !== null ? $catalog->find($c->app_key) : null;
        $driver = $app?->driver !== null ? app($app->driver) : null;
        if ($c === null || ! $driver instanceof TwoWay || ! in_array($c->status, ['connected', 'error'], true)) {
            return;
        }
        $event = mb_substr((string) ($this->payload['event'] ?? 'update'), 0, 60);
        try {
            $r = $driver->webhook($c->data(), $this->payload);
        } catch (NeedsReconnect $e) {
            $health->failed($c, $e->getMessage(), true);

            return;
        }
        if ($r['created'] + $r['updated'] > 0) {
            SyncRun::query()->create(['connection_id' => $c->id, 'status' => 'ok', 'created' => $r['created'], 'updated' => $r['updated'], 'skipped' => $r['skipped'],
                'note' => 'Live update: '.$event, 'started_at' => CarbonImmutable::now(), 'finished_at' => CarbonImmutable::now()]);
            $c->forceFill(['last_synced_at' => CarbonImmutable::now()])->save();
        }
    }
}
