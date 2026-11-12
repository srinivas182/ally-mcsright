<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\BillingController;
use Modules\Billing\Http\PaymentsController;
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
});

// Payment provider events: one URL for the platform, on the HQ host; signature-checked, no session.
Route::domain((string) config('core.hq_host'))->post('billing/webhook', [PaymentsController::class, 'webhook'])->middleware('throttle:600,1')->name('billing.webhook');

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq')->name('hq.')->group(function (): void {
    Route::get('billing', [BillingController::class, 'hq'])->name('billing');
    Route::post('billing/plans', [BillingController::class, 'hqSavePlan'])->name('billing.plans.store');
    Route::put('billing/plans/{plan}', [BillingController::class, 'hqSavePlan'])->name('billing.plans.update');
    Route::post('billing/tiers', [BillingController::class, 'hqSaveTiers'])->name('billing.tiers');
    Route::get('payments', [PaymentsController::class, 'hq'])->name('payments');
    Route::post('payments/options', [PaymentsController::class, 'setOptions'])->name('payments.options');
    Route::post('tenants/{tenant}/billing-exception', [PaymentsController::class, 'exception'])->name('tenants.billing-exception');
    Route::middleware('password.confirm')->group(function (): void {
        Route::post('payments/keys', [PaymentsController::class, 'saveKeys'])->name('payments.keys');
        Route::post('payments/mode', [PaymentsController::class, 'setMode'])->name('payments.mode');
    });
});
