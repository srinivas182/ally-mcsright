<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

/**
 * SX2: hand-written SQL named a brokerage table without saying which brokerage it is for (no tenant_id), while a
 * brokerage is the current tenant. Use the query builder (it adds the brokerage condition) or add
 * "where tenant_id = ?" with the current brokerage's id.
 */
final class UnguardedTenantQuery extends RuntimeException
{
    public function __construct(string $table)
    {
        parent::__construct("Refusing raw SQL on brokerage table [{$table}] without a tenant_id condition. Use the query builder or limit it to the current brokerage.");
    }
}
