<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

use Illuminate\Database\SQLiteConnection;

/** SQLite connection whose queries are brokerage-guarded (TenantGuard). */
final class GuardedSQLiteConnection extends SQLiteConnection
{
    use GuardsTenantQueries;
}
