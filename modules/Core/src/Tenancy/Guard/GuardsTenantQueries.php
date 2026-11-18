<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

/** For database connections: their query builders limit brokerage queries to the brokerage (TenantGuard). */
trait GuardsTenantQueries
{
    public function query()
    {
        return new TenantGuardedBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }
}
