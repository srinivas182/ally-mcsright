<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Insights\Http\InsightsController;

// Reports and KPIs (specs/insights/01).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('insights')->name('insights.')->group(function (): void {
    Route::get('/', [InsightsController::class, 'index'])->name('index');
    Route::get('deals', [InsightsController::class, 'drill'])->middleware('throttle:120,1')->name('drill');
    Route::post('goals', [InsightsController::class, 'goal'])->name('goals');
    Route::get('me', [InsightsController::class, 'me'])->name('me');
    Route::get('agents/{agent}', [InsightsController::class, 'me'])->name('agent');
});
