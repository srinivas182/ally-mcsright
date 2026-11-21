<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Listings\Http\Controllers\HqIdxController;
use Modules\Listings\Http\Controllers\HqMlsController;
use Modules\Listings\Http\Controllers\HqPhotosPlacesController;
use Modules\Listings\Http\Controllers\HqSearchController;
use Modules\Listings\Http\Controllers\ListingsController;
use Modules\Listings\Http\Controllers\MlsSettingsController;

// HQ → MLS connections (specs/websites/02).
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.mls.manage'])
    ->prefix('hq/mls')
    ->name('hq.mls.')
    ->group(function (): void {
        Route::get('/', [HqMlsController::class, 'index'])->name('index');
        Route::post('sources', [HqMlsController::class, 'store'])->name('sources.store');
        Route::patch('sources/{source}', [HqMlsController::class, 'update'])->name('sources.update');
        Route::post('sources/{source}/credentials', [HqMlsController::class, 'credentials'])->middleware('password.confirm')->name('sources.credentials');
        Route::post('sources/{source}/test', [HqMlsController::class, 'test'])->middleware('throttle:10,1,mls-test')->name('sources.test');
        Route::post('sources/{source}/sync', [HqMlsController::class, 'sync'])->middleware('throttle:10,1,mls-sync')->name('sources.sync');
        Route::post('profiles', [HqMlsController::class, 'saveProfile'])->name('profiles.save');
        Route::post('licences/{licence}/approve', [HqMlsController::class, 'approve'])->name('licences.approve');
        Route::post('licences/{licence}/revoke', [HqMlsController::class, 'revoke'])->name('licences.revoke');
        // SR1: the fast search server.
        Route::get('search', [HqSearchController::class, 'index'])->name('search');
        Route::post('search/rebuild', [HqSearchController::class, 'rebuild'])->middleware('throttle:3,10')->name('search.rebuild');
        // C03: photo tags and nearby schools and transit.
        Route::get('photos-places', [HqPhotosPlacesController::class, 'index'])->name('photos-places');
        Route::post('photos-places/run', [HqPhotosPlacesController::class, 'run'])->middleware('throttle:3,10')->name('photos-places.run');
    });

// Settings → MLS (brokerage owners).
// IDX1: HQ › IDX coverage: open states, the MLS board directory and the data connectors.
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.mls.manage'])
    ->prefix('hq/idx')
    ->name('hq.idx.')
    ->group(function (): void {
        Route::get('/', [HqIdxController::class, 'index'])->name('index');
        Route::put('states/{code}', [HqIdxController::class, 'state'])->where('code', '[A-Za-z]{2}')->name('states.update');
        Route::post('boards', [HqIdxController::class, 'saveBoard'])->name('boards.store');
        Route::put('boards/{board}', [HqIdxController::class, 'saveBoard'])->where('board', '[0-9A-HJKMNP-TV-Z]{26}')->name('boards.update');
        Route::put('connectors/{connector}', [HqIdxController::class, 'saveConnector'])->where('connector', '[a-z_]{2,30}')->name('connectors.update');
        // IDX4: the approval queue.
        $sub = '[0-9A-HJKMNP-TV-Z]{26}';
        Route::post('subscriptions/{subscription}/move', [HqIdxController::class, 'move'])->where('subscription', $sub)->name('subscriptions.move');
        Route::post('subscriptions/{subscription}/connector', [HqIdxController::class, 'override'])->where('subscription', $sub)->name('subscriptions.connector');
        Route::post('subscriptions/{subscription}/live', [HqIdxController::class, 'live'])->where('subscription', $sub)->middleware('password.confirm')->name('subscriptions.live');
        Route::post('boards/{board}/agreement', [HqIdxController::class, 'agreementPdf'])->where('board', $sub)->name('boards.agreement'); // IDX6
        Route::post('subscriptions/{subscription}/send', [HqIdxController::class, 'sendNow'])->where('subscription', $sub)->middleware('password.confirm')->name('subscriptions.send');
        Route::get('subscriptions/{subscription}/agreement.pdf', [HqIdxController::class, 'copy'])->where('subscription', $sub)->name('subscriptions.copy');
        Route::post('subscriptions/{subscription}/cancel', [HqIdxController::class, 'cancel'])->where('subscription', $sub)->middleware('password.confirm')->name('subscriptions.cancel');
    });

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])
    ->prefix('settings/mls')
    ->name('settings.mls.')
    ->group(function (): void {
        Route::get('/', [MlsSettingsController::class, 'index'])->name('index');
        Route::post('{source}/request', [MlsSettingsController::class, 'request'])->name('request');
        // IDX4: the brokerage's MLS boards, each on its own track.
        Route::put('demo', [MlsSettingsController::class, 'demo'])->name('demo'); // IDX5
        Route::post('boards', [MlsSettingsController::class, 'addBoard'])->middleware('throttle:20,1')->name('boards.store');
        Route::put('boards/{subscription}', [MlsSettingsController::class, 'submitBoard'])->where('subscription', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['password.confirm', 'throttle:20,1'])->name('boards.update');
        Route::get('boards/{subscription}/agreement.pdf', [MlsSettingsController::class, 'copy'])->where('subscription', '[0-9A-HJKMNP-TV-Z]{26}')->name('boards.copy'); // IDX6
        Route::delete('boards/{subscription}', [MlsSettingsController::class, 'cancelBoard'])->where('subscription', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('password.confirm')->name('boards.destroy');
    });

// Back office → Listings.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'throttle:120,1,listing-search'])
    ->prefix('listings')
    ->name('listings.')
    ->group(function (): void {
        Route::get('/', [ListingsController::class, 'index'])->name('index');
        Route::get('{listing}', [ListingsController::class, 'show'])->where('listing', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('show');
    });
