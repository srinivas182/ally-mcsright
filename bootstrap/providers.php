<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\Core\CoreServiceProvider;
use Modules\Ledger\LedgerServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
];
