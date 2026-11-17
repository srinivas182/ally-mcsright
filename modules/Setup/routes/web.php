<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Setup\Http\GuideController;
use Modules\Setup\Http\ImportController;

// SU1 (specs/setup/01): bring everything from the old system's export files.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('setup')->name('setup.')->group(function (): void {
    // SU2 (specs/setup/02): the guided setup.
    Route::get('guide', [GuideController::class, 'show'])->name('guide');
    Route::post('guide/describe', [GuideController::class, 'describe'])->middleware('throttle:10,1')->name('guide.describe');
    Route::post('guide', [GuideController::class, 'apply'])->middleware('throttle:20,1')->name('guide.apply');
    Route::get('import', [ImportController::class, 'index'])->name('import');
    Route::post('import', [ImportController::class, 'upload'])->middleware('throttle:20,1')->name('import.upload');
    Route::get('import/{import}', [ImportController::class, 'show'])->where('import', '[0-9A-HJKMNP-TV-Z]{26}')->name('import.show');
    Route::put('import/{import}', [ImportController::class, 'remap'])->where('import', '[0-9A-HJKMNP-TV-Z]{26}')->name('import.remap');
    Route::post('import/{import}/confirm', [ImportController::class, 'confirm'])->where('import', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:10,1')->name('import.confirm');
    Route::post('import/{import}/undo', [ImportController::class, 'undo'])->where('import', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:10,1')->name('import.undo');
});
