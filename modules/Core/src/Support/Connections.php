<?php

declare(strict_types=1);

namespace Modules\Core\Support;

/**
 * Logical database connections (architecture v2, specs/core/00).
 *
 * control: global control-plane data (cells, tenants, domains, packages).
 * cell:    tenant-owned data.
 * Both fall back to the default connection until the cells are split.
 */
final class Connections
{
    public static function control(): ?string
    {
        $name = config('core.connections.control');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public static function cell(): ?string
    {
        $name = config('core.connections.cell');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
