<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Watchtower\Http\PublicEndpoints;
use Modules\Core\Watchtower\Http\WatchtowerController;

/* OB1 (specs/core/20): Watchtower. HQ's pages, then three doors that use no session (see PublicEndpoints). */
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/watchtower')->name('hq.watchtower.')->group(function (): void {
    $ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';
    Route::get('/', [WatchtowerController::class, 'index'])->name('index');
    Route::post('settings', [WatchtowerController::class, 'settings'])->name('settings');
    Route::post('uptime-address', [WatchtowerController::class, 'uptimeAddress'])->middleware('password.confirm')->name('uptime');
    Route::get('{incident}', [WatchtowerController::class, 'show'])->where('incident', $ulid)->name('show');
    Route::post('{incident}', [WatchtowerController::class, 'update'])->where('incident', $ulid)->name('update');
    Route::post('{incident}/explain', [WatchtowerController::class, 'analyse'])->where('incident', $ulid)->middleware('throttle:10,1')->name('explain');
});

Route::get('up/scheduler', [PublicEndpoints::class, 'scheduler'])->name('watchtower.scheduler');
Route::post('webhooks/uptime/{token}', [PublicEndpoints::class, 'uptime'])->where('token', '[A-Za-z0-9]{16,128}')->middleware('throttle:60,1')->name('watchtower.uptime');
Route::post('watchtower/browser-error', [PublicEndpoints::class, 'browserError'])->middleware('throttle:20,1')->name('watchtower.browser');
