<?php

declare(strict_types=1);
use Illuminate\Support\ServiceProvider;

/*
|--------------------------------------------------------------------------
| Platform architecture rules
|--------------------------------------------------------------------------
|
| These rules run on every pull request. They grow with each sprint
| (tenant scoping, module boundaries, AI data protection) so that the
| standards in the architecture document are enforced by CI, not memory.
|
*/

arch('no debugging calls are left in application or module code')
    ->expect(['App', 'Modules'])
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit']);

arch('env() is only read inside config files')
    ->expect(['App', 'Modules'])
    ->not->toUse('env');

arch('module code uses strict types')
    ->expect('Modules')
    ->toUseStrictTypes();
