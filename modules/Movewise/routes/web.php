<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Movewise\Http\Controllers\HqMovewiseController;

// HQ → Movewise (specs/movewise/01): states, managing broker partners, the legal checklist and MLS feeds.
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/movewise')->name('hq.movewise.')->group(function (): void {
    Route::get('/', [HqMovewiseController::class, 'index'])->name('index');
    Route::post('partners', [HqMovewiseController::class, 'savePartner'])->middleware('password.confirm')->name('partners.store');
    Route::post('partners/{partner}', [HqMovewiseController::class, 'savePartner'])->where('partner', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('password.confirm')->name('partners.update');
    Route::post('states/{state}/partner', [HqMovewiseController::class, 'assign'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('states.partner');
    Route::post('states/{state}/checklist', [HqMovewiseController::class, 'check'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('states.check');
    Route::post('states/{state}/switch', [HqMovewiseController::class, 'switch'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('states.switch');
    Route::post('states/{state}/feeds', [HqMovewiseController::class, 'requestFeed'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('states.feeds');
});
