<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AI\Brand\Http\BrandVoiceController;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

/* BV1 (specs/core/08): Brand Voice and the brand guide. */
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('settings/brand-voice')->name('settings.brand-voice.')->group(function (): void {
    Route::get('/', [BrandVoiceController::class, 'show'])->name('show');
    Route::post('guide', [BrandVoiceController::class, 'upload'])->middleware('throttle:5,60')->name('guide');
    Route::post('/', [BrandVoiceController::class, 'save'])->name('save');
});
