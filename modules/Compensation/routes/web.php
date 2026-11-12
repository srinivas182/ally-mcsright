<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Compensation\Http\CompensationController;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::prefix('compensation')->name('compensation.')->group(function (): void {
        Route::get('plans', [CompensationController::class, 'index'])->name('plans');
        Route::post('plans', [CompensationController::class, 'store'])->name('plans.store');
        Route::put('plans/{plan}/draft', [CompensationController::class, 'saveDraft'])->name('plans.draft');
        Route::post('plans/{plan}/default', [CompensationController::class, 'makeDefault'])->name('plans.default');
        Route::post('plans/{plan}/archive', [CompensationController::class, 'archive'])->name('plans.archive');
        Route::post('versions/{version}/publish', [CompensationController::class, 'publish'])->name('versions.publish');
        Route::post('assignments', [CompensationController::class, 'assign'])->name('assign');
        Route::get('fees', [CompensationController::class, 'fees'])->name('fees');
        Route::post('fees', [CompensationController::class, 'publishFee'])->name('fees.publish');
        Route::get('credits', [CompensationController::class, 'credits'])->name('credits');
        Route::post('credits', [CompensationController::class, 'grantCredit'])->name('credits.grant');
        Route::get('simulator', [CompensationController::class, 'simulator'])->name('simulator');
        Route::post('simulate', [CompensationController::class, 'simulate'])->middleware('throttle:60,1')->name('simulate');
        Route::get('profitability', [CompensationController::class, 'profitability'])->name('profitability');
        Route::post('what-if', [CompensationController::class, 'whatIf'])->middleware('throttle:20,1')->name('what-if');
    });
    Route::get('my-plan', [CompensationController::class, 'myPlan'])->name('compensation.my-plan');
});
