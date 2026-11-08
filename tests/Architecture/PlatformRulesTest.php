<?php

declare(strict_types=1);

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

arch('AI providers are only reachable through the AI gateway')
    ->expect('Modules\AI\Providers')
    ->toOnlyBeUsedIn('Modules\AI');

arch('AI data protection classes are only used inside the AI module')
    ->expect('Modules\AI\Security')
    ->toOnlyBeUsedIn('Modules\AI');

arch('vector stores are only reachable through the AI retrieval services')
    ->expect(['Modules\AI\Retrieval\DatabaseVectorStore', 'Modules\AI\Embeddings'])
    ->toOnlyBeUsedIn('Modules\AI');
