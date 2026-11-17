<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Engage\Http\EngageController;

// Engage (EN1, specs/engage/01).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('engage')->name('engage.')->group(function (): void {
    Route::get('/', [EngageController::class, 'index'])->name('index');
    Route::post('coach', [EngageController::class, 'coach'])->middleware('throttle:10,1')->name('coach');
    Route::post('assistant', [EngageController::class, 'saveAssistant'])->name('assistant');
    Route::post('assistant/draft', [EngageController::class, 'draftNow'])->middleware('throttle:5,1')->name('assistant.draft');
    Route::post('checkins/{checkin}/send', [EngageController::class, 'send'])->where('checkin', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:60,1')->name('checkins.send');
    Route::post('checkins/{checkin}/skip', [EngageController::class, 'skip'])->where('checkin', '[0-9A-HJKMNP-TV-Z]{26}')->name('checkins.skip');
    Route::get('activity', [EngageController::class, 'activity'])->name('activity');
    Route::post('recognitions', [EngageController::class, 'recognise'])->middleware('throttle:30,1')->name('recognise');
    Route::post('voice', [EngageController::class, 'voice'])->middleware(['password.confirm', 'throttle:5,1'])->name('voice');
    Route::delete('voice', [EngageController::class, 'deleteVoice'])->name('voice.delete');
    Route::get('recognitions/{recognition}', [EngageController::class, 'recognition'])->where('recognition', '[0-9A-HJKMNP-TV-Z]{26}')->name('recognition');
    Route::get('recognitions/{recognition}/audio', [EngageController::class, 'audio'])->where('recognition', '[0-9A-HJKMNP-TV-Z]{26}')->name('recognition.audio');
});
