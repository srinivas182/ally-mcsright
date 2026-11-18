<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
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
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])
    ->prefix('settings/mls')
    ->name('settings.mls.')
    ->group(function (): void {
        Route::get('/', [MlsSettingsController::class, 'index'])->name('index');
        Route::post('{source}/request', [MlsSettingsController::class, 'request'])->name('request');
    });

// Back office → Listings.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'throttle:120,1,listing-search'])
    ->prefix('listings')
    ->name('listings.')
    ->group(function (): void {
        Route::get('/', [ListingsController::class, 'index'])->name('index');
        Route::get('{listing}', [ListingsController::class, 'show'])->where('listing', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('show');
    });
