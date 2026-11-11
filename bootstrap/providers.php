<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\AI\AiServiceProvider;
use Modules\Compensation\CompensationServiceProvider;
use Modules\Core\CoreServiceProvider;
use Modules\Crm\CrmServiceProvider;
use Modules\Ledger\LedgerServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
    CompensationServiceProvider::class,
    CrmServiceProvider::class,
    AiServiceProvider::class,
];
