<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/**
 * What every connector driver gets from the Connect module (specs/connect/00): a working access token (refreshed when
 * needed; NeedsReconnect when the sign-in is gone), HQ's RightAlly-level keys for the app, and the map between the
 * other app's ids and ours (so every sync is safe to repeat and never makes duplicates).
 */
interface ConnectorKit
{
    /** @throws NeedsReconnect */
    public function accessToken(ConnectionData $connection): string;

    /** @return array<string, string> */
    public function secrets(string $appKey): array;

    /** Our record for the other app's id, if it was synced before. */
    public function localId(ConnectionData $connection, string $entity, string $externalId): ?int;

    public function remember(ConnectionData $connection, string $entity, string $externalId, int $localId): void;

    /** Who connected the app (imports act as this person, with their access). */
    public function connectedBy(ConnectionData $connection): ?int;

    /** The other app's id for one of our records, if it was synced before. */
    public function externalId(ConnectionData $connection, string $entity, int $localId): ?string;

    /** @return list<ConnectionData> this brokerage's working connections to an app (only while HQ has the app on) */
    public function connections(string $appKey): array;

    public function connection(int $id): ?ConnectionData;

    /** The connection's own address for the other app's webhooks (central host; no secrets in it). */
    public function webhookUrl(ConnectionData $connection): string;

    /** @param  array<string, mixed>  $settings  merged into the connection's settings */
    public function saveSettings(ConnectionData $connection, array $settings): void;

    /** @param  array<string, string>  $credentials  merged into the connection's encrypted credentials */
    public function saveCredentials(ConnectionData $connection, array $credentials): void;

    /** App-level webhooks about this account (a page id, …) go to this connection (AppWebhooks). */
    public function routeTo(ConnectionData $connection, string $externalKey): void;

    /** Stops routing webhooks to this connection (all its accounts). */
    public function unroute(ConnectionData $connection): void;
}
