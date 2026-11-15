<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Connect\Http\AppsController;
use Modules\Connect\Http\HqAppsController;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

$app = '[a-z0-9_]{2,40}';
$ulid = '[0-9A-Za-z]{26}';

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->name('connect.')->group(function () use ($app, $ulid): void {
    Route::get('apps', [AppsController::class, 'index'])->name('apps');
    Route::get('apps/{app}', [AppsController::class, 'show'])->where('app', $app)->name('apps.show');
    // Connecting stores sign-in details: confirm the password first.
    Route::post('apps/{app}/connect', [AppsController::class, 'connect'])->where('app', $app)->middleware(['password.confirm', 'throttle:20,1'])->name('apps.connect');
    Route::post('apps/{app}/request', [AppsController::class, 'request'])->where('app', $app)->middleware('throttle:20,1')->name('apps.request');
    Route::delete('connections/{connection}', [AppsController::class, 'disconnect'])->where('connection', $ulid)->name('connections.delete');
    Route::post('connections/{connection}/sync', [AppsController::class, 'sync'])->where('connection', $ulid)->middleware('throttle:10,1')->name('connections.sync');
    Route::post('connections/{connection}/ping', [AppsController::class, 'ping'])->where('connection', $ulid)->middleware('throttle:10,1')->name('connections.ping');
    Route::post('connections/{connection}/pause', [AppsController::class, 'pause'])->where('connection', $ulid)->name('connections.pause');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.apps.view'])->get('hq/apps', [HqAppsController::class, 'index'])->name('hq.apps');
