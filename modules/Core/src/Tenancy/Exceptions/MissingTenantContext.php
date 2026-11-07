<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-owned data is read or written without a tenant context.
 * This is a programming error, never a user error.
 */
final class MissingTenantContext extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("No tenant context while accessing tenant-owned model [{$model}].");
    }

    public static function none(): self
    {
        return new self('No tenant context is set.');
    }
}
