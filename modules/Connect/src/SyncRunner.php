<?php

declare(strict_types=1);

namespace Modules\Connect;

use Carbon\CarbonImmutable;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\SyncRun;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\NeedsReconnect;

/**
 * One sync run for a connection (specs/connect/01): the app's driver does the work; the run is logged with what it
 * created, updated and skipped; health is updated. A failure never throws to the caller.
 */
final class SyncRunner
{
    public function __construct(private readonly AppCatalog $catalog, private readonly Health $health) {}

    public function run(Connection $c): ?SyncRun
    {
        $app = $this->catalog->find($c->app_key);
        if ($app === null || $app->driver === null || in_array($c->status, ['paused', 'needs_reconnect'], true) || ! app(AppRegistry::class)->enabled($app)) {
            return null;
        }
        $run = SyncRun::query()->create(['connection_id' => $c->id, 'status' => 'running', 'started_at' => CarbonImmutable::now()]);
        try {
            $driver = app($app->driver);
            if (! $driver instanceof Connector) {
                throw new \LogicException($app->driver.' is not a connector.');
            }
            $r = $driver->sync($c->data());
            $run->forceFill(['status' => 'ok', 'created' => $r['created'], 'updated' => $r['updated'], 'skipped' => $r['skipped'], 'note' => $r['note'] !== null ? mb_substr($r['note'], 0, 500) : null,
                'finished_at' => CarbonImmutable::now()])->save();
            $c->forceFill(['cursor' => $r['cursor'] ?? $c->cursor, 'last_synced_at' => CarbonImmutable::now()])->save();
            $this->health->succeeded($c);
            if ($r['more'] ?? false) {
                Jobs\SyncConnection::dispatch($c->id)->delay(now()->addSeconds(5)); // carry on with a big first import
            }
        } catch (NeedsReconnect $e) {
            $run->forceFill(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => CarbonImmutable::now()])->save();
            $this->health->failed($c, $e->getMessage() !== '' ? $e->getMessage() : 'The sign-in was revoked or expired.', true);
        } catch (\Throwable $e) {
            report($e);
            $run->forceFill(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => CarbonImmutable::now()])->save();
            $this->health->failed($c, mb_substr($e->getMessage(), 0, 300));
        }

        return $run;
    }
}
