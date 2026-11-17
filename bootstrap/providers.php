<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\Ads\AdsServiceProvider;
use Modules\AI\AiServiceProvider;
use Modules\Appointments\AppointmentsServiceProvider;
use Modules\Billing\BillingServiceProvider;
use Modules\Compensation\CompensationServiceProvider;
use Modules\Connect\ConnectServiceProvider;
use Modules\Core\CoreServiceProvider;
use Modules\Crm\CrmServiceProvider;
use Modules\Forms\FormsServiceProvider;
use Modules\Insights\InsightsServiceProvider;
use Modules\Learn\LearnServiceProvider;
use Modules\Ledger\LedgerServiceProvider;
use Modules\Listings\ListingsServiceProvider;
use Modules\ListIq\ListIqServiceProvider;
use Modules\Movewise\MovewiseServiceProvider;
use Modules\Outreach\OutreachServiceProvider;
use Modules\Payouts\PayoutsServiceProvider;
use Modules\Pricing\PricingServiceProvider;
use Modules\Recruiting\RecruitingServiceProvider;
use Modules\Roster\RosterServiceProvider;
use Modules\Sites\SitesServiceProvider;
use Modules\Team\TeamServiceProvider;
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
    FormsServiceProvider::class,
    TransactionsServiceProvider::class,
    ListingsServiceProvider::class,
    SitesServiceProvider::class,
    ConnectServiceProvider::class,
    AdsServiceProvider::class,
    MovewiseServiceProvider::class,
    RecruitingServiceProvider::class,
    OutreachServiceProvider::class,
    AppointmentsServiceProvider::class,
    PricingServiceProvider::class,
    ListIqServiceProvider::class,
    InsightsServiceProvider::class,
    TeamServiceProvider::class,
    LearnServiceProvider::class,
];
