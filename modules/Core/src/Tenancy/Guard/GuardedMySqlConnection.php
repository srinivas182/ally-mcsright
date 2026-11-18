<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

use Illuminate\Database\MySqlConnection;

/** MySql connection whose queries are brokerage-guarded (TenantGuard). */
final class GuardedMySqlConnection extends MySqlConnection
{
    use GuardsTenantQueries;
}
