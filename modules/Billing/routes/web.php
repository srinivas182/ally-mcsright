<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\BillingController;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::get('plans', [BillingController::class, 'plans'])->name('billing.plans');
    Route::get('settings/agent-plans', [BillingController::class, 'settings'])->name('billing.settings');
    Route::post('settings/agent-plans', [BillingController::class, 'saveSettings'])->name('billing.settings.save');
    Route::post('settings/agent-plans/{plan}', [BillingController::class, 'savePrice'])->name('billing.price');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq')->name('hq.')->group(function (): void {
    Route::get('billing', [BillingController::class, 'hq'])->name('billing');
    Route::post('billing/plans', [BillingController::class, 'hqSavePlan'])->name('billing.plans.store');
    Route::put('billing/plans/{plan}', [BillingController::class, 'hqSavePlan'])->name('billing.plans.update');
    Route::post('billing/tiers', [BillingController::class, 'hqSaveTiers'])->name('billing.tiers');
});
