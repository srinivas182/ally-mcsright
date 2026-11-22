<?php

declare(strict_types=1);

use Modules\Core\Services\Catalog;
use Modules\Core\Services\ProviderGuides;

/*
| HQ4: every outside provider tells people, in plain words, where to get its keys; nothing is left without a guide.
*/

it('has a plain-words guide for every provider of every outside service', function (): void {
    $missing = [];
    foreach (Catalog::all() as $service => $def) {
        foreach (array_keys($def['providers']) as $provider) {
            $guide = ProviderGuides::for($service, $provider);
            if ($guide === null || $guide['steps'] === [] || ($guide['site'] !== '' && ! str_starts_with($guide['site'], 'https://'))) {
                $missing[] = "{$service}.{$provider}";
            }
        }
    }

    expect($missing)->toBe([])
        ->and(array_diff(array_keys(ProviderGuides::ALL), collect(Catalog::all())->flatMap(fn (array $d, string $s): array => array_map(fn (string $p): string => "{$s}.{$p}", array_keys($d['providers'])))->all()))->toBe([]);
});
