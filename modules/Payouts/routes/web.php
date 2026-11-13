<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Payouts\Http\PayoutsController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::get('finance/receipts', [PayoutsController::class, 'receipts'])->name('payouts.receipts');
    Route::post('finance/receipts/{deal}', [PayoutsController::class, 'record'])->name('payouts.receipts.record');
    Route::post('finance/receipts/{receipt}/accept', [PayoutsController::class, 'accept'])->where('receipt', '[0-9A-Z]{26}')->name('payouts.receipts.accept');
    Route::get('my-money', [PayoutsController::class, 'mine'])->name('payouts.mine');
    Route::post('my-money/bank', [PayoutsController::class, 'setBank'])->middleware(['password.confirm', 'throttle:5,1'])->name('payouts.bank');
});
