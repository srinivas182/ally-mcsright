<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

use Illuminate\Database\MariaDbConnection;

/** MariaDb connection whose queries are brokerage-guarded (TenantGuard). */
final class GuardedMariaDbConnection extends MariaDbConnection
{
    use GuardsTenantQueries;
}
