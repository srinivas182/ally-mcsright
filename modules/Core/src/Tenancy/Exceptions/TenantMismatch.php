<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when code tries to write a record for a different tenant than the
 * current context, or to move a record between tenants.
 */
final class TenantMismatch extends RuntimeException
{
    public static function onCreate(string $model): self
    {
        return new self("Refusing to create [{$model}] for a tenant other than the current tenant.");
    }

    public static function onUpdate(string $model): self
    {
        return new self("The tenant of [{$model}] cannot be changed.");
    }
}
