<?php

declare(strict_types=1);

namespace Modules\Connect;

use Illuminate\Support\Facades\DB;
use Modules\Connect\Models\Connection;
use Modules\Connect\OAuth\OAuthFlow;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/** The connector kit drivers use (specs/connect/00). */
final class Kit implements ConnectorKit
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function accessToken(ConnectionData $connection): string
    {
        $c = Connection::query()->find($connection->id) ?? throw new NeedsReconnect('The connection was removed.');
        $app = app(AppCatalog::class)->find($c->app_key) ?? throw new NeedsReconnect('This app is no longer offered.');

        return app(OAuthFlow::class)->accessToken($c, $app);
    }

    public function secrets(string $appKey): array
    {
        $app = app(AppCatalog::class)->find($appKey);

        return $app !== null ? app(AppRegistry::class)->secrets($app) : [];
    }

    public function localId(ConnectionData $connection, string $entity, string $externalId): ?int
    {
        $id = DB::connection(Connections::cell())->table('connect_external_ids')->where('connection_id', $connection->id)->where('entity', $entity)
            ->where('external_id', $externalId)->value('local_id');

        return $id !== null ? (int) $id : null;
    }

    public function remember(ConnectionData $connection, string $entity, string $externalId, int $localId): void
    {
        DB::connection(Connections::cell())->table('connect_external_ids')->updateOrInsert(['connection_id' => $connection->id, 'entity' => $entity, 'external_id' => $externalId],
            ['tenant_id' => $this->tenants->id(), 'local_id' => $localId, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function connectedBy(ConnectionData $connection): ?int
    {
        $by = Connection::query()->whereKey($connection->id)->value('connected_by');

        return $by !== null ? (int) $by : null;
    }
}
