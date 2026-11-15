<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/**
 * A connector that keeps RightAlly and the other app in step both ways (specs/connect/05): the other app tells us
 * about changes within seconds (signed webhooks to the connection's own address), and our changes go back to it.
 */
interface TwoWay extends Connector
{
    /**
     * Is this webhook really from the other app? (Checked before anything is read or queued.)
     *
     * @param  array<string, string>  $headers  lower-cased header names
     */
    public function verifyWebhook(string $raw, array $headers): bool;

    /**
     * Handles one webhook (queued, after we have answered the other app).
     *
     * @param  array<string, mixed>  $payload
     * @return array{created: int, updated: int, skipped: int}
     */
    public function webhook(ConnectionData $connection, array $payload): array;

    /** Sends one of our records to the other app ("contact", "contact_update" or "note"); safe to repeat. */
    public function push(ConnectionData $connection, string $entity, int $localId): void;

    /** The connection is being removed: switch off anything we registered with the other app. Never throws. */
    public function disconnected(ConnectionData $connection): void;
}
