<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Insights\Http\InsightsController;
use Modules\Insights\Http\ReportsController;

// Reports and KPIs (specs/insights/01).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('insights')->name('insights.')->group(function (): void {
    Route::get('/', [InsightsController::class, 'index'])->name('index');
    Route::get('deals', [InsightsController::class, 'drill'])->middleware('throttle:120,1')->name('drill');
    Route::post('goals', [InsightsController::class, 'goal'])->name('goals');
    Route::get('me', [InsightsController::class, 'me'])->name('me');
    Route::get('reports', [ReportsController::class, 'index'])->name('reports');
    Route::post('reports/run', [ReportsController::class, 'run'])->middleware('throttle:60,1')->name('reports.run');
    Route::post('reports/export', [ReportsController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');
    Route::post('reports/ask', [ReportsController::class, 'ask'])->middleware(['throttle:20,1', 'feature:crm.ai'])->name('reports.ask');
    Route::post('reports', [ReportsController::class, 'save'])->name('reports.store');
    Route::put('reports/{report}', [ReportsController::class, 'save'])->name('reports.update');
    Route::delete('reports/{report}', [ReportsController::class, 'destroy'])->name('reports.destroy');
    Route::get('agents/{agent}', [InsightsController::class, 'me'])->name('agent');
});
