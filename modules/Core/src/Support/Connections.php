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

    /**
     * Audit writes (sealing). In production a user with INSERT and SELECT only on audit_events
     * (plus audit_heads and audit_outbox housekeeping); falls back to the cell connection.
     */
    public static function audit(): ?string
    {
        $name = config('core.connections.audit');

        return is_string($name) && $name !== '' ? $name : self::cell();
    }

    /**
     * Retention deletes only (the one place allowed to DELETE audit events).
     */
    public static function auditMaintenance(): ?string
    {
        $name = config('core.connections.audit_maintenance');

        return is_string($name) && $name !== '' ? $name : self::cell();
    }
}
