<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ads\Http\AdsController;
use Modules\Ads\Http\SocialController;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

// Listing ads (specs/ads/01).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('ads')->name('ads.')->group(function (): void {
    Route::get('/', [AdsController::class, 'index'])->name('index');
    Route::get('new', [AdsController::class, 'create'])->name('create');
    Route::post('draft', [AdsController::class, 'draft'])->middleware('throttle:20,1')->name('draft');
    Route::post('/', [AdsController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::post('{campaign}/pause', [AdsController::class, 'pause'])->where('campaign', '[0-9A-Za-z]{26}')->name('pause');
    Route::post('{campaign}/resume', [AdsController::class, 'resume'])->where('campaign', '[0-9A-Za-z]{26}')->name('resume');
    Route::post('{campaign}/end', [AdsController::class, 'end'])->where('campaign', '[0-9A-Za-z]{26}')->name('end');
});

// Social posts (specs/connect/19, C7d-1).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('social')->name('ads.social.')->group(function (): void {
    Route::get('/', [SocialController::class, 'index'])->name('index');
    Route::post('/', [SocialController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::post('{post}/cancel', [SocialController::class, 'cancel'])->where('post', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('cancel');
});
