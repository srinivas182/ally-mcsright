<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Outreach\Http\HqCardsController;
use Modules\Outreach\Http\HqTextingController;
use Modules\Outreach\Http\MarketingController;
use Modules\Outreach\Http\OutreachController;

// Outreach (MK1, specs/crm/20).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('outreach')->name('outreach.')->group(function (): void {
    Route::get('/', [OutreachController::class, 'index'])->name('index');
    Route::get('booking', [OutreachController::class, 'booking'])->name('booking');
    Route::post('booking', [OutreachController::class, 'saveBooking'])->name('booking.save');
    Route::get('videos', [OutreachController::class, 'videos'])->name('videos');
    Route::post('videos', [OutreachController::class, 'storeVideo'])->middleware('throttle:20,1')->name('videos.store');
    Route::delete('videos/{video}', [OutreachController::class, 'removeVideo'])->where('video', '[0-9A-HJKMNP-TV-Z]{26}')->name('videos.remove');
    Route::get('calls', [OutreachController::class, 'calls'])->name('calls');
    Route::get('signature', [MarketingController::class, 'signature'])->name('signature'); // MK2
    Route::get('content', [MarketingController::class, 'content'])->name('content');
    Route::get('homeowners', [MarketingController::class, 'homeowners'])->name('homeowners');
    Route::post('homeowners', [MarketingController::class, 'startHomeowners'])->middleware('throttle:10,1')->name('homeowners.start');
    Route::get('cards', [MarketingController::class, 'cards'])->name('cards');
    Route::post('cards', [MarketingController::class, 'requestCard'])->middleware('throttle:30,1')->name('cards.request');
    Route::post('calls/{contact}', [OutreachController::class, 'logCall'])->where('contact', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:120,1')->name('calls.log');
});

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('settings/texting')->name('settings.texting.')->group(function (): void {
    Route::get('/', [OutreachController::class, 'texting'])->name('index');
    Route::post('/', [OutreachController::class, 'saveTexting'])->name('save');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/texting')->name('hq.texting.')->group(function (): void {
    Route::get('/', [HqTextingController::class, 'index'])->name('index');
    Route::post('{tenant}', [HqTextingController::class, 'decide'])->whereNumber('tenant')->name('decide');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/cards')->name('hq.cards.')->group(function (): void {
    Route::get('/', [HqCardsController::class, 'index'])->name('index');
    Route::get('export.csv', [HqCardsController::class, 'export'])->name('export');
    Route::post('sent', [HqCardsController::class, 'sent'])->name('sent');
});
