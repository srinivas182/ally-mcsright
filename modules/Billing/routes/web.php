<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\AffiliateController;
use Modules\Billing\Http\BillingController;
use Modules\Billing\Http\CallStatusController;
use Modules\Billing\Http\Credits\CreditsController;
use Modules\Billing\Http\Credits\HqCreditsController;
use Modules\Billing\Http\GoLiveController;
use Modules\Billing\Http\HealthCheckController;
use Modules\Billing\Http\PaymentsController;
use Modules\Billing\Http\SignupController;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::get('plans', [BillingController::class, 'plans'])->name('billing.plans');
    Route::get('settings/agent-plans', [BillingController::class, 'settings'])->name('billing.settings');
    Route::post('settings/agent-plans', [BillingController::class, 'saveSettings'])->name('billing.settings.save');
    Route::post('settings/agent-plans/{plan}', [BillingController::class, 'savePrice'])->name('billing.price');
    Route::post('settings/agent-plans-sponsor', [PaymentsController::class, 'sponsor'])->name('billing.sponsor');
    Route::post('plans/{plan}/checkout', [PaymentsController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
    Route::get('plans/portal', [PaymentsController::class, 'portal'])->middleware('throttle:10,1')->name('billing.portal');
    Route::get('settings/billing', [PaymentsController::class, 'billing'])->name('billing.brokerage');
    Route::post('settings/billing/checkout', [PaymentsController::class, 'brokerageCheckout'])->middleware('throttle:10,1')->name('billing.brokerage.checkout');
    Route::get('settings/billing/portal', [PaymentsController::class, 'brokeragePortal'])->middleware('throttle:10,1')->name('billing.brokerage.portal');
    Route::post('settings/agent-plans-subsidy', [PaymentsController::class, 'subsidy'])->name('billing.subsidy');
    Route::get('settings/partner', [PaymentsController::class, 'partner'])->name('billing.partner-earnings');
    Route::get('settings/credits', [CreditsController::class, 'index'])->name('credits.index');
    Route::get('credits/quote', [CreditsController::class, 'quote'])->middleware('throttle:120,1')->name('credits.quote');
    Route::post('settings/credits/allot', [CreditsController::class, 'allot'])->name('credits.allot');
    Route::post('settings/credits/policy', [CreditsController::class, 'policy'])->name('credits.policy');
    Route::post('settings/credits/buy', [CreditsController::class, 'buy'])->middleware('throttle:10,1')->name('credits.buy');
    Route::post('settings/credits/buy-saved', [CreditsController::class, 'buySaved'])->middleware('throttle:5,1')->name('credits.buy-saved');
    Route::post('settings/credits/autopay', [CreditsController::class, 'autopay'])->name('credits.autopay');
    Route::delete('settings/credits/card', [CreditsController::class, 'removeCard'])->name('credits.card.remove');
    Route::get('settings/credits/receipts/{purchase}', [CreditsController::class, 'receipt'])->where('purchase', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('credits.receipt');
    Route::get('settings/credits/export', [CreditsController::class, 'export'])->middleware('throttle:20,1')->name('credits.export');
    Route::get('switch', [GoLiveController::class, 'index'])->name('switch');
    Route::post('settings/partner/connect', [PaymentsController::class, 'connect'])->middleware('throttle:10,1')->name('billing.connect');
    Route::get('settings/refer', [AffiliateController::class, 'brokerage'])->name('billing.refer'); // RF1
});

// RF1 (specs/billing/08): referral links and a referrer's private dashboard, on the RightAlly host.
Route::domain((string) config('core.hq_host'))->middleware('web')->group(function (): void {
    Route::get('r/{code}', [AffiliateController::class, 'link'])->where('code', '[a-z0-9]{3,24}')->middleware('throttle:60,1')->name('affiliate.link');
    Route::get('affiliate/{token}', [AffiliateController::class, 'dashboard'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('affiliate.dashboard');
});

// Public partner lookup for rightally.io ("My brokerage is a partner").
Route::domain((string) config('core.hq_host'))->get('partners/{code}', [PaymentsController::class, 'partnerLookup'])->middleware('throttle:30,1')->name('billing.partner');

// The free Brokerage Health Check (specs/billing/06): public on the RightAlly host; reports open with a private link.
Route::domain((string) config('core.hq_host'))->middleware('web')->group(function (): void {
    $token = '[A-Za-z0-9]{40}';
    Route::get('health-check', [HealthCheckController::class, 'start'])->name('health.start');
    Route::get('health-check/es', [HealthCheckController::class, 'startEs'])->name('health.start.es');
    Route::post('health-check', [HealthCheckController::class, 'store'])->middleware('throttle:5,60')->name('health.store');
    Route::get('health-check/r/{token}', [HealthCheckController::class, 'report'])->where('token', $token)->middleware('throttle:60,1')->name('health.report');
    Route::get('health-check/r/{token}/preview', [HealthCheckController::class, 'preview'])->where('token', $token)->middleware('throttle:60,1')->name('health.preview');
    Route::get('health-check/r/{token}/logo', [HealthCheckController::class, 'logo'])->where('token', $token)->middleware('throttle:60,1')->name('health.logo');
    Route::get('start', [SignupController::class, 'start'])->name('signup.start');
    Route::post('start', [SignupController::class, 'store'])->middleware('throttle:10,60')->name('signup.store');
    Route::get('agents', [SignupController::class, 'agents'])->name('signup.agents');
    Route::post('agents', [SignupController::class, 'storeAgent'])->middleware('throttle:10,60')->name('signup.agents.store');
    Route::get('start/address', [SignupController::class, 'address'])->middleware('throttle:60,1')->name('signup.address');
    Route::get('start/welcome/{signup}', [SignupController::class, 'welcome'])->where('signup', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:60,1')->name('signup.welcome');
    Route::post('health-check/r/{token}/delete', [HealthCheckController::class, 'destroy'])->where('token', $token)->middleware('throttle:10,1')->name('health.delete');
});

// Twilio's report of each finished call (real minutes for call allowances); signature-checked, no session.
Route::domain((string) config('core.hq_host'))->post('billing/twilio/call-status', CallStatusController::class)->middleware('throttle:600,1')->name('billing.call-status');

// Payment provider events: one URL for the platform, on the HQ host; signature-checked, no session.
Route::domain((string) config('core.hq_host'))->post('billing/webhook', [PaymentsController::class, 'webhook'])->middleware('throttle:600,1')->name('billing.webhook');

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq')->name('hq.')->group(function (): void {
    Route::get('billing', [BillingController::class, 'hq'])->name('billing');
    Route::get('health-checks', [HealthCheckController::class, 'hq'])->name('health-checks');
    Route::get('go-live', [GoLiveController::class, 'hq'])->name('go-live');
    Route::get('affiliates', [AffiliateController::class, 'hq'])->name('affiliates');
    Route::post('affiliates', [AffiliateController::class, 'addPerson'])->middleware('throttle:20,1')->name('affiliates.store');
    Route::post('affiliates/referrals/{referral}/paid', [AffiliateController::class, 'markPaid'])->whereNumber('referral')->middleware('password.confirm')->name('affiliates.paid');
    Route::post('go-live/{tenant}/coach', [GoLiveController::class, 'coach'])->name('go-live.coach');
    Route::get('health-checks/{check}', [HealthCheckController::class, 'hqShow'])->where('check', '[0-9A-HJKMNP-TV-Z]{26}')->name('health-checks.show');
    Route::get('health-checks/{check}/preview', [HealthCheckController::class, 'hqPreview'])->where('check', '[0-9A-HJKMNP-TV-Z]{26}')->name('health-checks.preview');
    Route::get('health-checks/{check}/logo', [HealthCheckController::class, 'hqLogo'])->where('check', '[0-9A-HJKMNP-TV-Z]{26}')->name('health-checks.logo');
    Route::post('billing/plans', [BillingController::class, 'hqSavePlan'])->name('billing.plans.store');
    Route::put('billing/plans/{plan}', [BillingController::class, 'hqSavePlan'])->name('billing.plans.update');
    Route::post('billing/tiers', [BillingController::class, 'hqSaveTiers'])->name('billing.tiers');
    Route::get('payments', [PaymentsController::class, 'hq'])->name('payments');
    Route::get('credits', [HqCreditsController::class, 'index'])->name('credits');
    Route::post('credits/enforce', [HqCreditsController::class, 'enforce'])->middleware('password.confirm')->name('credits.enforce');
    Route::post('credits/go-live', [HqCreditsController::class, 'goLive'])->middleware('password.confirm')->name('credits.go-live');
    Route::post('credits/margins', [HqCreditsController::class, 'margins'])->name('credits.margins');
    Route::post('credits/rates/apply-suggested', [HqCreditsController::class, 'applySuggested'])->name('credits.apply-suggested');
    Route::post('credits/costs/{key}', [HqCreditsController::class, 'catalog'])->where('key', '[a-z0-9_.]+')->name('credits.costs');
    Route::post('credits/value', [HqCreditsController::class, 'value'])->name('credits.value');
    Route::post('credits/rates', [HqCreditsController::class, 'rate'])->name('credits.rate');
    Route::post('credits/packages/{package}', [HqCreditsController::class, 'packageCredits'])->name('credits.package');
    Route::post('credits/tenants/{tenant}/maps-covered', [HqCreditsController::class, 'mapsCovered'])->where('tenant', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('credits.maps-covered');
    Route::post('credits/packs', [HqCreditsController::class, 'savePack'])->name('credits.packs.store');
    Route::put('credits/packs/{pack}', [HqCreditsController::class, 'savePack'])->where('pack', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('credits.packs.update');
    Route::post('credits/grant', [HqCreditsController::class, 'grant'])->name('credits.grant');
    Route::post('billing/packages', [PaymentsController::class, 'savePackage'])->name('billing.packages.store');
    Route::put('billing/packages/{package}', [PaymentsController::class, 'savePackage'])->name('billing.packages.update');
    Route::post('payments/options', [PaymentsController::class, 'setOptions'])->name('payments.options');
    Route::post('payments/card-fee', [PaymentsController::class, 'setCardFee'])->name('payments.card_fee');
    Route::post('tenants/{tenant}/billing-exception', [PaymentsController::class, 'exception'])->name('tenants.billing-exception');
    Route::middleware('password.confirm')->group(function (): void {
        Route::post('payments/keys', [PaymentsController::class, 'saveKeys'])->name('payments.keys');
        Route::post('payments/mode', [PaymentsController::class, 'setMode'])->name('payments.mode');
    });
});
