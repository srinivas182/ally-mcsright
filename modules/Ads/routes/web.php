<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ads\Http\AdsController;
use Modules\Ads\Http\AutopilotController;
use Modules\Ads\Http\HqStudioController;
use Modules\Ads\Http\SocialController;
use Modules\Ads\Http\StudioController;
use Modules\Core\Http\Middleware\EnsureHqConsole;
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

// Ally Studio (specs/grow/13).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('studio')->name('ads.studio.')->group(function (): void {
    Route::get('/', [StudioController::class, 'index'])->name('index');
    Route::get('brand', [StudioController::class, 'brand'])->name('brand');
    Route::post('brand', [StudioController::class, 'saveBrand'])->middleware('throttle:20,1')->name('brand.save');
    Route::get('create', [StudioController::class, 'create'])->name('create');
    Route::post('designs', [StudioController::class, 'makeDesigns'])->middleware('throttle:10,1')->name('designs.store');
    Route::get('designs/{design}/download', [StudioController::class, 'download'])->where('design', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('designs.download');
    Route::get('autopilot', [AutopilotController::class, 'index'])->name('autopilot');
    Route::post('autopilot', [AutopilotController::class, 'save'])->name('autopilot.save');
    Route::post('autopilot/{item}/approve', [AutopilotController::class, 'approve'])->where('item', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('autopilot.approve');
    Route::post('autopilot/{item}/skip', [AutopilotController::class, 'skip'])->where('item', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('autopilot.skip');
    Route::post('designs/{design}/post', [StudioController::class, 'post'])->where('design', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('designs.post');
});
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/studio')->name('hq.studio.')->group(function (): void {
    Route::get('/', [HqStudioController::class, 'index'])->name('index');
    Route::post('packages/{package}', [HqStudioController::class, 'save'])->where('package', 'studio_[a-z]+')->name('packages.save');
});
