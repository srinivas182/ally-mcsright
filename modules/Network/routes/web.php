<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Network\Http\NetworkController;
use Modules\Network\Http\PublicProfileController;

// AR1 (specs/network/01): the agent referral network.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->name('network.')->group(function (): void {
    Route::get('network', [NetworkController::class, 'index'])->name('index');
    Route::post('network/profile', [NetworkController::class, 'saveProfile'])->middleware('throttle:20,1')->name('profile');
    Route::post('network/referrals', [NetworkController::class, 'send'])->middleware('throttle:20,1')->name('send');
    Route::post('network/referrals/{referral}/accept', [NetworkController::class, 'accept'])->where('referral', '[0-9A-HJKMNP-TV-Z]{26}')->name('accept');
    Route::post('network/referrals/{referral}/decline', [NetworkController::class, 'decline'])->where('referral', '[0-9A-HJKMNP-TV-Z]{26}')->name('decline');
    Route::post('network/referrals/{referral}/update', [NetworkController::class, 'update'])->where('referral', '[0-9A-HJKMNP-TV-Z]{26}')->name('update');
    Route::post('network/referrals/{referral}/fee', [NetworkController::class, 'fee'])->where('referral', '[0-9A-HJKMNP-TV-Z]{26}')->name('fee');
    Route::get('settings/network', [NetworkController::class, 'settings'])->name('settings');
    Route::post('settings/network', [NetworkController::class, 'saveSettings'])->name('settings.save');
    Route::post('settings/network/{profile}/verify', [NetworkController::class, 'verify'])->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')->name('verify');
});

// Public agent pages on the RightAlly host, only for agents who chose to have one.
Route::domain((string) config('core.hq_host'))->middleware('web')->group(function (): void {
    Route::get('agents/{code}', [PublicProfileController::class, 'show'])->where('code', '[a-z0-9-]{2,40}')->middleware('throttle:60,1')->name('network.public');
});
