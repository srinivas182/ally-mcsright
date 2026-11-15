<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Modules\Connect\Http\AppsController;
use Modules\Connect\Http\HqAppsController;
use Modules\Connect\Http\McpConsentController;
use Modules\Connect\Http\OAuthController;
use Modules\Connect\Mcp\RightAllyServer;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

$app = '[a-z0-9_]{2,40}';
$ulid = '[0-9A-Za-z]{26}';

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->name('connect.')->group(function () use ($app, $ulid): void {
    Route::get('apps', [AppsController::class, 'index'])->name('apps');
    Route::get('apps/{app}', [AppsController::class, 'show'])->where('app', $app)->name('apps.show');
    // Connecting stores sign-in details: confirm the password first.
    Route::post('apps/{app}/connect', [AppsController::class, 'connect'])->where('app', $app)->middleware(['password.confirm', 'throttle:20,1'])->name('apps.connect');
    // One click: sign in to the other app and approve (no password step: the other app's sign-in is the check).
    Route::post('apps/{app}/oauth', [OAuthController::class, 'start'])->where('app', $app)->middleware('throttle:20,1')->name('apps.oauth');
    Route::get('apps/oauth/complete', [OAuthController::class, 'complete'])->middleware('throttle:20,1')->name('apps.oauth.complete');
    Route::post('apps/{app}/request', [AppsController::class, 'request'])->where('app', $app)->middleware('throttle:20,1')->name('apps.request');
    Route::delete('connections/{connection}', [AppsController::class, 'disconnect'])->where('connection', $ulid)->name('connections.delete');
    Route::post('connections/{connection}/sync', [AppsController::class, 'sync'])->where('connection', $ulid)->middleware('throttle:10,1')->name('connections.sync');
    Route::post('connections/{connection}/ping', [AppsController::class, 'ping'])->where('connection', $ulid)->middleware('throttle:10,1')->name('connections.ping');
    Route::post('connections/{connection}/pause', [AppsController::class, 'pause'])->where('connection', $ulid)->name('connections.pause');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.apps.view'])->group(function (): void {
    Route::get('hq/apps', [HqAppsController::class, 'index'])->name('hq.apps');
    Route::get('hq/connect', [HqAppsController::class, 'setup'])->name('hq.connect');
    // Saving RightAlly's sign-in apps: confirm the password first.
    Route::post('hq/connect/apps/{app}', [HqAppsController::class, 'saveApp'])->where('app', '[a-z0-9_]{2,40}')->middleware('password.confirm')->name('hq.connect.app');
    Route::post('hq/connect/{provider}', [HqAppsController::class, 'saveClient'])->whereIn('provider', ['google', 'microsoft'])->middleware('password.confirm')->name('hq.connect.save');
});

// AI assistants (MCP, specs/connect/03): the server, OAuth discovery and client registration, per brokerage address.
Mcp::web('mcp', RightAllyServer::class)->middleware(['auth:api', 'throttle:120,1']);
Mcp::oauthRoutes();
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::post('apps/ai_assistants/settings', [McpConsentController::class, 'settings'])->name('connect.mcp.settings');
    Route::post('apps/ai_assistants/disconnect', [McpConsentController::class, 'disconnect'])->name('connect.mcp.disconnect');
});

// The one address the sign-in services send people back to, for every brokerage (specs/connect/02).
Route::domain((string) config('core.sso_host'))->middleware(['web', 'throttle:30,1'])
    ->get('connect/oauth/callback', [OAuthController::class, 'callback'])->name('connect.oauth.callback');
