<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\AI\AiServiceProvider;
use Modules\Billing\BillingServiceProvider;
use Modules\Compensation\CompensationServiceProvider;
use Modules\Core\CoreServiceProvider;
use Modules\Crm\CrmServiceProvider;
use Modules\Ledger\LedgerServiceProvider;
use Modules\Payouts\PayoutsServiceProvider;
use Modules\Roster\RosterServiceProvider;
use Modules\Transactions\TransactionsServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
    CompensationServiceProvider::class,
    CrmServiceProvider::class,
    AiServiceProvider::class,
    BillingServiceProvider::class,
    PayoutsServiceProvider::class,
    RosterServiceProvider::class,
    TransactionsServiceProvider::class,
];
