<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy;

/**
 * Prefixes cache keys with the current tenant so cached data can never be shared
 * between tenants (architecture v2 section 3).
 */
final class TenantCache
{
    public function __construct(private readonly TenantContext $context) {}

    public function key(string $key): string
    {
        return 't:'.$this->context->id().':'.$key;
    }
}
