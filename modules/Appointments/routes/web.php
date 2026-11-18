<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Appointments\Http\AppointmentsController;
use Modules\Appointments\Http\RecordingsController;
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
    // VO2: recorded appointments (everyone's consent first), notes and private coaching.
    Route::get('coaching', [RecordingsController::class, 'coaching'])->name('coaching');
    Route::post('{consultation}/recording', [RecordingsController::class, 'start'])->where('consultation', $id)->middleware('throttle:10,1')->name('recording.start');
    Route::post('recordings/{recording}/piece', [RecordingsController::class, 'piece'])->where('recording', $id)->middleware('throttle:240,1')->name('recording.piece');
    Route::post('recordings/{recording}/stop', [RecordingsController::class, 'stop'])->where('recording', $id)->name('recording.stop');
    Route::post('recordings/{recording}/retry', [RecordingsController::class, 'retry'])->where('recording', $id)->middleware('throttle:10,1')->name('recording.retry');
    Route::post('recordings/{recording}/share', [RecordingsController::class, 'share'])->where('recording', $id)->name('recording.share');
    Route::post('recordings/{recording}/delete', [RecordingsController::class, 'discard'])->where('recording', $id)->name('recording.delete');
});
