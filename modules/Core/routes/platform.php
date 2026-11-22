<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Hq\HqDashboardController;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;
use Modules\Core\Privacy\Http\PrivacyController;
use Modules\Core\Security\Http\ControlsController;
use Modules\Core\Switches\Http\HqPlatformController;
use Modules\Core\Switches\Http\PlatformController;

/*
| FL1 (specs/core/16): areas and seats, outside services, approval rules and support access.
*/
Route::middleware(['web', EnsureTenantHost::class, 'auth'])->prefix('settings')->name('settings.')->group(function (): void {
    Route::middleware('can:core.platform.manage')->group(function (): void {
        Route::get('features', [PlatformController::class, 'features'])->name('features');
        Route::post('features', [PlatformController::class, 'toggle'])->name('features.toggle');
        Route::post('features/seats', [PlatformController::class, 'seat'])->name('features.seat');
        Route::get('services', [PlatformController::class, 'services'])->name('services');
        Route::post('services/{service}', [PlatformController::class, 'saveService'])->where('service', '[a-z_]{2,40}')->middleware('password.confirm')->name('services.save');
        Route::post('services/{service}/check', [PlatformController::class, 'checkService'])->where('service', '[a-z_]{2,40}')->middleware('throttle:20,1')->name('services.check');
        Route::post('services/{service}/mode', [PlatformController::class, 'serviceMode'])->where('service', '[a-z_]{2,40}')->middleware('password.confirm')->name('services.mode');
    });
    Route::get('approvals', [PlatformController::class, 'approvals'])->name('approvals');
    Route::post('approvals/rules', [PlatformController::class, 'approvalRule'])->middleware('password.confirm')->name('approvals.rule');
    Route::post('approvals/{request_ulid}', [PlatformController::class, 'vote'])->where('request_ulid', '[0-9A-HJKMNP-TV-Z]{26}')->name('approvals.vote');
    // TR1 (specs/core/18): privacy requests and how long data is kept.
    Route::get('privacy', [PrivacyController::class, 'index'])->name('privacy');
    Route::post('privacy/retention', [PrivacyController::class, 'retention'])->middleware('password.confirm')->name('privacy.retention');
    Route::post('privacy/{ulid}/complete', [PrivacyController::class, 'complete'])->where('ulid', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('password.confirm')->name('privacy.complete');
    Route::post('privacy/{ulid}/decline', [PrivacyController::class, 'decline'])->where('ulid', '[0-9A-HJKMNP-TV-Z]{26}')->name('privacy.decline');
    Route::get('support-access', [PlatformController::class, 'support'])->name('support-access');
    Route::post('support-access', [PlatformController::class, 'supportPolicy'])->name('support-access.policy');
    Route::post('support-access/close', [PlatformController::class, 'supportClose'])->name('support-access.close');
    Route::post('support-access/{request_ulid}', [PlatformController::class, 'supportDecide'])->where('request_ulid', '[0-9A-HJKMNP-TV-Z]{26}')->name('support-access.decide');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq')->name('hq.')->group(function (): void {
    Route::get('dashboard', HqDashboardController::class)->name('dashboard'); // HQ2
    Route::get('controls', [ControlsController::class, 'index'])->name('controls'); // TR1: SOC 2 evidence
    Route::get('controls/download', [ControlsController::class, 'download'])->name('controls.download');
    Route::get('switches', [HqPlatformController::class, 'switches'])->name('switches');
    Route::post('switches', [HqPlatformController::class, 'everyone'])->middleware('password.confirm')->name('switches.everyone');
    Route::post('switches/tenants/{tenant}', [HqPlatformController::class, 'tenant'])->name('switches.tenant');
    Route::post('switches/tenants/{tenant}/seats', [HqPlatformController::class, 'seats'])->name('switches.seats');
    Route::get('services', [HqPlatformController::class, 'services'])->name('services');
    Route::post('services/{service}', [HqPlatformController::class, 'saveService'])->where('service', '[a-z_]{2,40}')->middleware('password.confirm')->name('services.save');
    Route::post('services/{service}/check', [HqPlatformController::class, 'checkService'])->where('service', '[a-z_]{2,40}')->middleware('throttle:20,1')->name('services.check');
    Route::post('services/{service}/mode', [HqPlatformController::class, 'serviceMode'])->where('service', '[a-z_]{2,40}')->middleware('password.confirm')->name('services.mode');
});

// TR1: a person's copy of their information, by the signed link emailed to them (no sign-in).
Route::middleware(['web', EnsureTenantHost::class, 'throttle:20,1'])->get('privacy-copy/{request_ulid}', [PrivacyController::class, 'copy'])
    ->where('request_ulid', '[0-9A-HJKMNP-TV-Z]{26}')->name('privacy.copy');
