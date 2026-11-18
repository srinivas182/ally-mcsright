<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

use Illuminate\Database\PostgresConnection;

/** Postgres connection whose queries are brokerage-guarded (TenantGuard). */
final class GuardedPostgresConnection extends PostgresConnection
{
    use GuardsTenantQueries;
}
