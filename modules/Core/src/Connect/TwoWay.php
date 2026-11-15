<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/**
 * A connector that keeps RightAlly and the other app in step both ways (specs/connect/05): the other app tells us
 * about changes within seconds (signed webhooks to the connection's own address), and our changes go back to it.
 */
interface TwoWay extends ReceivesWebhooks
{
    /** Sends one of our records to the other app ("contact", "contact_update" or "note"); safe to repeat. */
    public function push(ConnectionData $connection, string $entity, int $localId): void;
}
