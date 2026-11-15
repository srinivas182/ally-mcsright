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
}
