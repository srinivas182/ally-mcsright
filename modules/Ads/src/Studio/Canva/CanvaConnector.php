<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Canva;

use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;

/** The Canva connection (C7d-3): nothing to sync; Studio uses it when the person opens a design in Canva. */
final class CanvaConnector implements Connector
{
    public function verify(array $credentials, array $settings): string
    {
        return 'Canva';
    }

    public function sync(ConnectionData $connection): array
    {
        return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'cursor' => null, 'note' => null];
    }
}
