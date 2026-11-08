<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Ledger\Http\Controllers\AccountController;
use Modules\Ledger\Http\Controllers\JournalController;
use Modules\Ledger\Http\Controllers\PeriodController;

Route::middleware(['web', 'auth', EnsureTenantWorkspace::class])
    ->prefix('finance')
    ->name('finance.')
    ->group(function (): void {
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::post('accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
        Route::get('accounts/{account}/statement', [AccountController::class, 'statement'])->name('accounts.statement');
        Route::get('accounts/{account}/statement.csv', [AccountController::class, 'export'])->name('accounts.export');

        Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
        Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');
        Route::get('adjustments/create', [JournalController::class, 'createAdjustment'])->name('adjustments.create');
        Route::post('adjustments', [JournalController::class, 'storeAdjustment'])->name('adjustments.store');

        Route::get('periods', [PeriodController::class, 'index'])->name('periods.index');
        Route::post('periods/{period}/close', [PeriodController::class, 'close'])->name('periods.close');
    });
