<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Api\Http\ApiAuth;
use Modules\Api\Http\DeveloperController;
use Modules\Api\Http\HqPartnersController;
use Modules\Api\Http\V1Controller;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;

// Public API v1 (API1, specs/api/01): stateless, on each brokerage's own address.
Route::middleware(['tenant', EnsureTenantWorkspace::class])->prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::get('openapi.json', [DeveloperController::class, 'openapi'])->middleware('throttle:60,1')->name('openapi');
    Route::middleware(ApiAuth::class)->group(function (): void {
        Route::get('me', [V1Controller::class, 'me'])->name('me');
        Route::get('contacts', [V1Controller::class, 'contacts'])->name('contacts');
        Route::post('contacts', [V1Controller::class, 'createContact'])->name('contacts.store');
        Route::get('contacts/{contact}', [V1Controller::class, 'contact'])->where('contact', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('contacts.show');
        Route::patch('contacts/{contact}', [V1Controller::class, 'updateContact'])->where('contact', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('contacts.update');
        Route::post('contacts/{contact}/notes', [V1Controller::class, 'note'])->where('contact', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('contacts.notes');
        Route::post('contacts/{contact}/tasks', [V1Controller::class, 'task'])->where('contact', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('contacts.tasks');
        Route::get('tasks', [V1Controller::class, 'tasks'])->name('tasks');
        Route::get('deals', [V1Controller::class, 'deals'])->name('deals');
        Route::get('deals/{deal}', [V1Controller::class, 'deal'])->where('deal', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('deals.show');
        Route::get('webhooks', [V1Controller::class, 'webhooks'])->name('webhooks');
        Route::post('webhooks', [V1Controller::class, 'subscribe'])->name('webhooks.store');
        Route::delete('webhooks/{webhook}', [V1Controller::class, 'unsubscribe'])->where('webhook', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('webhooks.destroy');
    });
});

// Settings › API and partner apps.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('settings/api')->name('settings.api.')->group(function (): void {
    Route::get('/', [DeveloperController::class, 'show'])->name('show');
    Route::post('keys', [DeveloperController::class, 'create'])->middleware(['password.confirm', 'throttle:10,1'])->name('keys.store');
    Route::post('keys/{key}/revoke', [DeveloperController::class, 'revoke'])->where('key', '[0-9A-HJKMNP-TV-Z]{26}')->name('keys.revoke');
    Route::post('apps/{client}', [DeveloperController::class, 'allow'])->where('client', '[A-Za-z0-9-]{1,100}')->middleware('password.confirm')->name('apps.allow');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/partners')->name('hq.partners.')->group(function (): void {
    Route::get('/', [HqPartnersController::class, 'index'])->name('index');
    Route::post('/', [HqPartnersController::class, 'store'])->middleware('password.confirm')->name('store');
    Route::post('{client}', [HqPartnersController::class, 'toggle'])->where('client', '[A-Za-z0-9-]{1,100}')->name('toggle');
});
