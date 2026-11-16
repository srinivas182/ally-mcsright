<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Movewise\Http\Controllers\HqAuditController;
use Modules\Movewise\Http\Controllers\HqMovewiseController;
use Modules\Movewise\Http\Controllers\HqProgramController;
use Modules\Movewise\Http\Controllers\ProgramController;
use Modules\Movewise\Http\Controllers\VoiceRelayController;

// HQ → Movewise (specs/movewise/01): states, managing broker partners, the legal checklist and MLS feeds.
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/movewise')->name('hq.movewise.')->group(function (): void {
    Route::get('/', [HqMovewiseController::class, 'index'])->name('index');
    Route::post('partners', [HqMovewiseController::class, 'savePartner'])->middleware('password.confirm')->name('partners.store');
    Route::post('partners/{partner}', [HqMovewiseController::class, 'savePartner'])->where('partner', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('password.confirm')->name('partners.update');
    Route::post('states/{state}/partner', [HqMovewiseController::class, 'assign'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('states.partner');
    Route::post('states/{state}/checklist', [HqMovewiseController::class, 'check'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('states.check');
    Route::post('states/{state}/switch', [HqMovewiseController::class, 'switch'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('states.switch');
    Route::post('states/{state}/feeds', [HqMovewiseController::class, 'requestFeed'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('states.feeds');

    // MW3: the agent program — pricing per state, agent reviews and Movewise Agent seats.
    Route::get('program', [HqProgramController::class, 'index'])->name('program');
    Route::post('program/states/{state}/pricing', [HqProgramController::class, 'pricing'])->where('state', '[A-Z]{2}')->middleware('password.confirm')->name('program.pricing');
    Route::post('program/agents/{agent}/review', [HqProgramController::class, 'review'])->where('agent', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('program.review');
    Route::post('program/agents/{agent}/status', [HqProgramController::class, 'status'])->where('agent', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('program.status');
    Route::post('program/seats/{seat}', [HqProgramController::class, 'seat'])->where('seat', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('program.seat');
    Route::get('money', [HqProgramController::class, 'money'])->name('money');
    // MW8: launch checks and the audit packs.
    Route::get('launch', [HqAuditController::class, 'index'])->name('launch');
    Route::get('audit/fair-housing.csv', [HqAuditController::class, 'fairHousing'])->middleware('password.confirm')->name('audit.fair-housing');
    Route::get('audit/consent.csv', [HqAuditController::class, 'consent'])->middleware('password.confirm')->name('audit.consent');
    Route::post('money/invoices/{invoice}', [HqProgramController::class, 'invoice'])->where('invoice', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('password.confirm')->name('money.invoice');
    Route::post('money/ads', [HqProgramController::class, 'ads'])->middleware('password.confirm')->name('money.ads');
    Route::post('money/reviews/{connection}/remove', [HqProgramController::class, 'removeReview'])->where('connection', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('password.confirm')->name('money.reviews.remove');
    Route::post('money/settlements/{settlement}', [HqProgramController::class, 'settlement'])->whereNumber('settlement')->middleware('password.confirm')->name('money.settlement');
    Route::post('program/agents/{agent}/strike', [HqProgramController::class, 'strike'])->where('agent', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('password.confirm')->name('program.strike');
    Route::post('program/relays', [HqProgramController::class, 'relay'])->middleware('password.confirm')->name('program.relays');
    Route::post('program/refunds/{connection}', [HqProgramController::class, 'refund'])->where('connection', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('password.confirm')->name('program.refund');
});

// Back office → Movewise (specs/movewise/03): apply, profile card, terms, packages; owners confirm their agents.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('movewise')->name('movewise.')->group(function (): void {
    $u = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';
    Route::get('/', [ProgramController::class, 'show'])->name('program');
    Route::post('apply', [ProgramController::class, 'apply'])->middleware('throttle:20,1')->name('apply');
    Route::post('agents/{agent}/submit', [ProgramController::class, 'submit'])->where('agent', $u)->name('submit');
    Route::post('agents/{agent}/terms', [ProgramController::class, 'terms'])->where('agent', $u)->name('terms');
    Route::post('agents/{agent}/confirm', [ProgramController::class, 'confirm'])->where('agent', $u)->name('confirm');
    Route::post('packages', [ProgramController::class, 'savePackage'])->name('packages.store');
    Route::post('packages/{package}', [ProgramController::class, 'savePackage'])->where('package', $u)->name('packages.update');
    Route::post('packages/{package}/activate', [ProgramController::class, 'activate'])->where('package', $u)->name('packages.activate');
    Route::post('packages/{package}/pause', [ProgramController::class, 'pause'])->where('package', $u)->name('packages.pause');
    Route::get('introductions/{connection}', [ProgramController::class, 'introduction'])->where('connection', $u)->name('introductions.show');
    Route::post('introductions/{connection}', [ProgramController::class, 'decide'])->where('connection', $u)->middleware('throttle:30,1')->name('introductions.decide');
});

// MW8: calls through the Movewise relay (Twilio voice webhook for each relay number; Twilio-signed).
Route::middleware('throttle:600,1,movewise-voice')->post('webhooks/movewise/voice', VoiceRelayController::class)->name('movewise.voice');
