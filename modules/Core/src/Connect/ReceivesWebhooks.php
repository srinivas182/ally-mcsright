<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/**
 * A connector the other app tells about changes within seconds (signed webhooks to the connection's own address,
 * specs/connect/05). Two-way connectors (TwoWay) also send our changes back; read-only ones, like dotloop
 * (specs/connect/12), only receive.
 */
interface ReceivesWebhooks extends Connector
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

    /** The connection is being removed: switch off anything we registered with the other app. Never throws. */
    public function disconnected(ConnectionData $connection): void;
}
