<?php

declare(strict_types=1);

namespace Modules\Connect\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Connect\Jobs\SyncConnection;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\WebhookDelivery;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Every 15 minutes: queue a sync for each connected app that is due (its interval, default an hour); keep webhook
 * delivery history for 30 days (specs/connect/01).
 */
final class SyncConnections extends Command
{
    protected $signature = 'connect:sync';

    protected $description = 'Queue due syncs for connected apps and prune old webhook deliveries';

    public function handle(TenantContext $tenants, AppCatalog $catalog): int
    {
        $queued = 0;
        $syncing = array_values(array_map(fn ($a): string => $a->key, array_filter($catalog->all(), fn ($a): bool => $a->driver !== null)));
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $queued += $tenants->runAs($tenant, function () use ($syncing): int {
                WebhookDelivery::query()->where('created_at', '<', CarbonImmutable::now()->subDays(30))->delete();
                $n = 0;
                foreach (Connection::query()->whereIn('app_key', $syncing)->whereIn('status', ['connected', 'error'])->get() as $c) {
                    $every = max(15, (int) ($c->settings['interval_minutes'] ?? 60));
                    if ($c->last_synced_at === null || $c->last_synced_at->lte(CarbonImmutable::now()->subMinutes($every - 1))) {
                        SyncConnection::dispatch($c->id);
                        $n++;
                    }
                }

                return $n;
            });
        }
        $this->info("{$queued} sync(s) queued.");

        return self::SUCCESS;
    }
}
