<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Modules\Connect\Http\AppsController;
use Modules\Connect\Http\AppWebhookController;
use Modules\Connect\Http\CentralMcpController;
use Modules\Connect\Http\CentralMcpToken;
use Modules\Connect\Http\HqAppsController;
use Modules\Connect\Http\McpConsentController;
use Modules\Connect\Http\OAuthController;
use Modules\Connect\Http\WebhookInController;
use Modules\Connect\Mcp\RightAllyServer;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

$app = '[a-z0-9_]{2,40}';
$ulid = '[0-9A-Za-z]{26}';

// The shared AI assistant address for every brokerage (specs/connect/03, M2b). Registered first, so these win over
// the per-brokerage routes with the same paths.
Route::domain((string) config('core.mcp_host'))->group(function (): void {
    Route::get('.well-known/oauth-authorization-server{any?}', [CentralMcpController::class, 'authorizationServer'])->where('any', '(/.*)?');
    Route::get('.well-known/oauth-protected-resource{any?}', [CentralMcpController::class, 'protectedResource'])->where('any', '(/.*)?');
    Route::post('register', [CentralMcpController::class, 'register'])->middleware('throttle:20,1');
    Route::post('token', [CentralMcpController::class, 'token'])->middleware('throttle:60,1');
    Route::middleware(['web', 'throttle:30,1'])->group(function (): void {
        Route::get('authorize', [CentralMcpController::class, 'authorize'])->name('connect.mcp.central.authorize');
        Route::post('authorize/find', [CentralMcpController::class, 'find'])->middleware('throttle:10,1');
        Route::post('authorize/choose', [CentralMcpController::class, 'choose']);
        Route::get('oauth/return', [CentralMcpController::class, 'back']);
    });
    Mcp::web('mcp', RightAllyServer::class)->middleware([CentralMcpToken::class, 'auth:api', 'throttle:120,1']);
});

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->name('connect.')->group(function () use ($app, $ulid): void {
    Route::get('apps', [AppsController::class, 'index'])->name('apps');
    Route::get('apps/{app}', [AppsController::class, 'show'])->where('app', $app)->name('apps.show');
    // Connecting stores sign-in details: confirm the password first.
    Route::post('apps/{app}/connect', [AppsController::class, 'connect'])->where('app', $app)->middleware(['password.confirm', 'throttle:20,1'])->name('apps.connect');
    // One click: sign in to the other app and approve (no password step: the other app's sign-in is the check).
    Route::post('apps/{app}/oauth', [OAuthController::class, 'start'])->where('app', $app)->middleware('throttle:20,1')->name('apps.oauth');
    Route::get('apps/oauth/complete', [OAuthController::class, 'complete'])->middleware('throttle:20,1')->name('apps.oauth.complete');
    Route::post('apps/{app}/request', [AppsController::class, 'request'])->where('app', $app)->middleware('throttle:20,1')->name('apps.request');
    Route::post('apps/{app}/addon', [AppsController::class, 'addAddon'])->where('app', $app)->middleware(['password.confirm', 'throttle:10,1'])->name('apps.addon');
    Route::delete('apps/{app}/addon', [AppsController::class, 'cancelAddon'])->where('app', $app)->name('apps.addon.cancel');
    Route::post('apps/{app}/addon/pay-with', [AppsController::class, 'addonPayWith'])->where('app', $app)->middleware('password.confirm')->name('apps.addon.pay_with');
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
    Route::post('hq/connect/apps/{app}/price', [HqAppsController::class, 'savePrice'])->where('app', '[a-z0-9_]{2,40}')->middleware('password.confirm')->name('hq.connect.app.price');
    Route::post('hq/connect/{provider}', [HqAppsController::class, 'saveClient'])->whereIn('provider', ['google', 'microsoft'])->middleware('password.confirm')->name('hq.connect.save');
});

// AI assistants (MCP, specs/connect/03): the server, OAuth discovery and client registration, per brokerage address.
Mcp::web('mcp', RightAllyServer::class)->middleware(['auth:api', 'throttle:120,1']);
Mcp::oauthRoutes();
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::post('apps/ai_assistants/settings', [McpConsentController::class, 'settings'])->name('connect.mcp.settings');
    Route::post('apps/ai_assistants/disconnect', [McpConsentController::class, 'disconnect'])->name('connect.mcp.disconnect');
});

// Webhooks from two-way apps: one address per connection, signature-checked (specs/connect/05).
Route::domain((string) config('core.sso_host'))->middleware('throttle:600,1')
    ->post('connect/hooks/{app}/{tenant}/{connection}', WebhookInController::class)
    ->where(['app' => '[a-z0-9_]{2,40}', 'tenant' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'connection' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}'])->name('connect.hooks');
// Some apps (Mailchimp) check the address with a GET before they send anything.
Route::domain((string) config('core.sso_host'))->middleware('throttle:60,1')
    ->get('connect/hooks/{app}/{tenant}/{connection}', fn () => response('', 200))
    ->where(['app' => '[a-z0-9_]{2,40}', 'tenant' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'connection' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}'])->name('connect.hooks.check');

// App-level webhooks (one address per app, e.g. Meta), routed by account (specs/connect/10).
Route::domain((string) config('core.sso_host'))->middleware('throttle:1200,1')->group(function (): void {
    Route::get('connect/apps/{app}/webhook', [AppWebhookController::class, 'handshake'])->where('app', '[a-z0-9_]{2,40}');
    Route::post('connect/apps/{app}/webhook', [AppWebhookController::class, 'receive'])->where('app', '[a-z0-9_]{2,40}');
});

// The one address the sign-in services send people back to, for every brokerage (specs/connect/02).
Route::domain((string) config('core.sso_host'))->middleware(['web', 'throttle:30,1'])
    ->get('connect/oauth/callback', [OAuthController::class, 'callback'])->name('connect.oauth.callback');
