<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Transactions\Http\DealController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.minimal'])->prefix('deals')->name('deals.')->group(function (): void {
    Route::get('/', [DealController::class, 'index'])->name('index');
    Route::get('review', [DealController::class, 'queue'])->name('review');
    Route::post('/', [DealController::class, 'store'])->name('store');
    Route::get('{deal}', [DealController::class, 'show'])->name('show');
    Route::post('{deal}/status', [DealController::class, 'status'])->name('status');
    Route::post('{deal}/documents', [DealController::class, 'upload'])->middleware('throttle:60,1')->name('upload');
    Route::get('documents/{document}', [DealController::class, 'download'])->name('download');
    Route::post('items/{item}/review', [DealController::class, 'review'])->name('item.review');
});
