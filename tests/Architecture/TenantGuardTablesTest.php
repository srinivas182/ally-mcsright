<?php

declare(strict_types=1);

use Modules\Core\Safeguards\ControlTables;
use Modules\Core\Tenancy\Guard\TenantGuard;

/*
| Sprint 58: the brokerage guard (TenantGuard) must leave every platform table alone, also on one-database setups
| (local, tests) where they share the cell's database. A table a migration makes on the control connection, by name
| or through the migration's own connection, is listed in ControlTables::ALL or TenantGuard::PLATFORM.
*/
it('leaves every control-plane table out of the brokerage guard', function (): void {
    $found = [];
    foreach (array_merge(glob(__DIR__.'/../../modules/*/database/migrations/*.php') ?: [], glob(__DIR__.'/../../database/migrations/*.php') ?: []) as $file) {
        $s = (string) file_get_contents($file);
        preg_match_all("/Schema::connection\\(Connections::control\\(\\)\\)->create\\('([a-z0-9_]+)'/", $s, $m);
        $found = array_merge($found, $m[1]);
        if (preg_match('/\$(\w+) = Schema::connection\(Connections::control\(\)\)/', $s, $v) === 1) {
            preg_match_all('/\$'.$v[1]."->create\\('([a-z0-9_]+)'/", $s, $m2);
            $found = array_merge($found, $m2[1]);
        }
        // Migrations that choose the control connection for all their tables.
        if (preg_match('/function getConnection\(\): \?string\s*\{\s*return Connections::control\(\);/', $s) === 1) {
            preg_match_all("/->create\\('([a-z0-9_]+)'/", $s, $m3);
            $found = array_merge($found, $m3[1]);
        }
    }
    $found = array_values(array_unique($found));
    sort($found);

    expect(array_values(array_diff($found, ControlTables::ALL, TenantGuard::PLATFORM)))->toBe([]);
});
