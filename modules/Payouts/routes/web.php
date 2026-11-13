<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Payouts\Http\PayoutsController;
use Modules\Payouts\Http\RailsController;
use Modules\Payouts\Rails\RailWebhooks;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::get('finance/receipts', [PayoutsController::class, 'receipts'])->name('payouts.receipts');
    Route::post('finance/receipts/{deal}', [PayoutsController::class, 'record'])->name('payouts.receipts.record');
    Route::post('finance/receipts/{receipt}/accept', [PayoutsController::class, 'accept'])->where('receipt', '[0-9A-Z]{26}')->name('payouts.receipts.accept');
    Route::get('finance/payouts', [PayoutsController::class, 'runs'])->name('payouts.runs');
    Route::post('finance/payouts', [PayoutsController::class, 'buildRun'])->name('payouts.runs.build');
    Route::post('finance/payouts/{run}/approve', [PayoutsController::class, 'approveRun'])->name('payouts.runs.approve');
    Route::post('finance/payouts/{run}/cancel', [PayoutsController::class, 'cancelRun'])->name('payouts.runs.cancel');
    Route::post('finance/payouts/{run}/send', [PayoutsController::class, 'sendRun'])->middleware('password.confirm')->name('payouts.runs.send');
    Route::get('finance/payouts/{run}/files/{kind}', [PayoutsController::class, 'file'])->middleware('throttle:20,1')->name('payouts.runs.file');
    Route::post('finance/payout-settings', [PayoutsController::class, 'saveSettings'])->middleware('password.confirm')->name('payouts.settings');
    Route::post('finance/payouts/{run}/retry', [RailsController::class, 'retry'])->middleware('throttle:10,1')->name('payouts.runs.retry');
    Route::middleware('password.confirm')->prefix('finance/payout-rail')->name('payouts.rail.')->group(function (): void {
        Route::post('/', [RailsController::class, 'choose'])->name('choose');
        Route::post('dwolla/business', [RailsController::class, 'dwollaBusiness'])->name('dwolla.business');
        Route::post('dwolla/bank', [RailsController::class, 'dwollaBank'])->name('dwolla.bank');
        Route::post('dwolla/verify', [RailsController::class, 'dwollaVerify'])->name('dwolla.verify');
        Route::post('modern-treasury', [RailsController::class, 'modernTreasury'])->name('mt');
        Route::post('stripe', [RailsController::class, 'stripe'])->name('stripe');
    });
    Route::get('my-money', [PayoutsController::class, 'mine'])->name('payouts.mine');
    Route::get('my-money/statement', [PayoutsController::class, 'statement'])->name('payouts.statement');
    Route::post('my-money/w9', [PayoutsController::class, 'w9'])->middleware(['password.confirm', 'throttle:10,1'])->name('payouts.w9');
    Route::get('finance/tax', [PayoutsController::class, 'tax'])->name('payouts.tax');
    Route::get('finance/trust', [PayoutsController::class, 'trust'])->name('payouts.trust');
    Route::post('finance/trust/import', [PayoutsController::class, 'trustImport'])->name('payouts.trust.import');
    Route::post('finance/trust/match', [PayoutsController::class, 'trustMatch'])->name('payouts.trust.match');
    Route::post('finance/trust/approve', [PayoutsController::class, 'trustApprove'])->middleware('password.confirm')->name('payouts.trust.approve');
    Route::middleware('password.confirm')->group(function (): void {
        Route::get('finance/tax/export', [PayoutsController::class, 'taxExport'])->name('payouts.tax.export');
        Route::get('finance/tax/tin-file', [PayoutsController::class, 'tinFile'])->name('payouts.tax.tin-file');
    });
    Route::post('finance/tax/tin-results', [PayoutsController::class, 'tinResults'])->name('payouts.tax.tin-results');
    Route::post('my-money/invoices/{invoice}/pay', [PayoutsController::class, 'payInvoice'])->middleware('throttle:10,1')->name('payouts.invoice.pay');
    Route::get('finance/agent-billing', [PayoutsController::class, 'billing'])->name('payouts.billing');
    Route::post('finance/agent-billing/settings', [PayoutsController::class, 'billingSettings'])->name('payouts.billing.settings');
    Route::post('finance/agent-billing/items', [PayoutsController::class, 'billingItem'])->name('payouts.billing.items');
    Route::post('finance/agent-billing/assign', [PayoutsController::class, 'billingAssign'])->name('payouts.billing.assign');
    Route::post('finance/agent-billing/charge', [PayoutsController::class, 'billingCharge'])->name('payouts.billing.charge');
    Route::post('finance/agent-billing/plan', [PayoutsController::class, 'billingPlan'])->name('payouts.billing.plan');
    Route::post('my-money/get-paid-now', [PayoutsController::class, 'getPaidNow'])->middleware(['password.confirm', 'throttle:5,1'])->name('payouts.instant');
    Route::post('finance/get-paid-now', [PayoutsController::class, 'instantSettings'])->middleware('password.confirm')->name('payouts.instant.settings');
    Route::post('my-money/bank', [PayoutsController::class, 'setBank'])->middleware(['password.confirm', 'throttle:5,1'])->name('payouts.bank');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq')->name('hq.')->group(function (): void {
    Route::get('payout-rails', [RailsController::class, 'hq'])->name('payout-rails');
    Route::post('payout-rails/enable', [RailsController::class, 'hqEnable'])->name('payout-rails.enable');
    Route::post('payout-rails/instant-limits', [PayoutsController::class, 'instantLimits'])->name('payout-rails.instant');
    Route::post('payout-rails/keys', [RailsController::class, 'hqKeys'])->middleware('password.confirm')->name('payout-rails.keys');
});

// Signed payment-status webhooks on the HQ host (no session).
Route::domain((string) config('core.hq_host'))->middleware('throttle:600,1')->group(function (): void {
    Route::post('payout-rails/{rail}/webhook', [RailsController::class, 'webhook'])->whereIn('rail', ['dwolla', 'stripe'])->name('payout-rails.webhook');
    Route::post('payout-rails/modern-treasury/{tenant}/webhook', fn (Request $r, Tenant $tenant) => app(RailsController::class)
        ->webhook($r, 'modern_treasury', app(RailWebhooks::class), $tenant))->name('payout-rails.webhook.mt');
});
