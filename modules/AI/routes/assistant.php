<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AI\Assistant\AssistantController;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

// "Your AI Assistant" (specs/know/03): the bottom-right back-office chat.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('assistant')->name('assistant.')->group(function (): void {
    Route::get('/', [AssistantController::class, 'latest'])->name('latest');
    Route::post('ask', [AssistantController::class, 'ask'])->middleware('throttle:20,1,assistant')->name('ask');
    Route::post('turns/{turn}/do', [AssistantController::class, 'doIt'])->where('turn', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:20,1,assistant-do')->name('do');
    Route::post('turns/{turn}/cancel', [AssistantController::class, 'cancel'])->where('turn', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('cancel');
});
