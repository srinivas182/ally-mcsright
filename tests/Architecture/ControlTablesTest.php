<?php

declare(strict_types=1);

use Modules\Core\Safeguards\ControlTables;

/* DS2: every table a migration creates on the control connection is listed, so exports, restores and moves skip it. */
it('lists every control-plane table', function (): void {
    $found = [];
    foreach (array_merge(glob(__DIR__.'/../../modules/*/database/migrations/*.php') ?: [], glob(__DIR__.'/../../database/migrations/*.php') ?: []) as $file) {
        $s = (string) file_get_contents($file);
        preg_match_all("/Schema::connection\\(Connections::control\\(\\)\\)->create\\('([a-z0-9_]+)'/", $s, $m);
        $found = array_merge($found, $m[1]);
        if (preg_match('/\$(\w+) = Schema::connection\(Connections::control\(\)\)/', $s, $v) === 1) {
            preg_match_all('/\$'.$v[1]."->create\\('([a-z0-9_]+)'/", $s, $m2);
            $found = array_merge($found, $m2[1]);
        }
    }
    $found = array_values(array_unique($found));
    sort($found);

    expect(array_values(array_diff($found, ControlTables::ALL)))->toBe([]);
});
