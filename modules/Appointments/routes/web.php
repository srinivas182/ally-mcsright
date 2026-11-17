<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Appointments\Http\AppointmentsController;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

// Appointments (AP1, specs/listings/05).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('appointments')->name('appointments.')->group(function (): void {
    $id = '[0-9A-HJKMNP-TV-Z]{26}';
    Route::get('/', [AppointmentsController::class, 'index'])->name('index');
    Route::post('/', [AppointmentsController::class, 'start'])->name('start');
    Route::get('{consultation}', [AppointmentsController::class, 'show'])->where('consultation', $id)->name('show');
    Route::post('{consultation}/save', [AppointmentsController::class, 'save'])->where('consultation', $id)->middleware('throttle:120,1')->name('save');
    Route::post('{consultation}/signed', [AppointmentsController::class, 'signed'])->where('consultation', $id)->name('signed');
    Route::post('{consultation}/finish', [AppointmentsController::class, 'finish'])->where('consultation', $id)->name('finish');
    Route::get('{consultation}/agreement', [AppointmentsController::class, 'file'])->where('consultation', $id)->name('file');
});
