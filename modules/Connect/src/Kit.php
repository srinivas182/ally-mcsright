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

    public function externalId(ConnectionData $connection, string $entity, int $localId): ?string
    {
        $id = DB::connection(Connections::cell())->table('connect_external_ids')->where('connection_id', $connection->id)->where('entity', $entity)
            ->where('local_id', $localId)->value('external_id');

        return $id !== null ? (string) $id : null;
    }

    public function connections(string $appKey): array
    {
        $app = app(AppCatalog::class)->find($appKey);
        if ($app === null || ! app(AppRegistry::class)->ready($app)) {
            return [];
        }

        return array_values(Connection::query()->where('app_key', $appKey)->whereIn('status', ['connected', 'error'])->orderBy('id')->get()
            ->map(fn (Connection $c): ConnectionData => $c->data())->all());
    }

    public function connection(int $id): ?ConnectionData
    {
        return Connection::query()->find($id)?->data();
    }

    public function webhookUrl(ConnectionData $connection): string
    {
        return 'https://'.config('core.sso_host').'/connect/hooks/'.$connection->appKey.'/'.$this->tenants->current()->ulid.'/'.$connection->ulid;
    }

    public function saveSettings(ConnectionData $connection, array $settings): void
    {
        $c = Connection::query()->find($connection->id);
        $c?->forceFill(['settings' => array_merge($c->settings ?? [], $settings)])->save();
    }

    public function saveCredentials(ConnectionData $connection, array $credentials): void
    {
        $c = Connection::query()->find($connection->id);
        $c?->forceFill(['credentials' => array_merge($c->credentials ?? [], $credentials)])->save();
    }

    public function routeTo(ConnectionData $connection, string $externalKey): void
    {
        DB::connection(Connections::control())->table('connect_routes')->updateOrInsert(['app_key' => $connection->appKey, 'external_key' => $externalKey],
            ['tenant_id' => $this->tenants->id(), 'connection_id' => $connection->id, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function unroute(ConnectionData $connection): void
    {
        DB::connection(Connections::control())->table('connect_routes')->where('tenant_id', $this->tenants->id())->where('connection_id', $connection->id)->delete();
    }

    public function connectedBy(ConnectionData $connection): ?int
    {
        $by = Connection::query()->whereKey($connection->id)->value('connected_by');

        return $by !== null ? (int) $by : null;
    }
}
