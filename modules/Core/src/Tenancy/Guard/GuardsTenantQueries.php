<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

use Closure;

/**
 * For database connections: their query builders limit brokerage queries to the brokerage (TenantGuard), and
 * hand-written SQL on a brokerage table must carry its own tenant_id condition (SX2).
 */
trait GuardsTenantQueries
{
    public function query()
    {
        return new TenantGuardedBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function run($query, $bindings, Closure $callback)
    {
        TenantGuard::checkRaw($this, (string) $query);

        return parent::run($query, $bindings, $callback);
    }
}
