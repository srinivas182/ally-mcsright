<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Recruiting\Http\RecruitingController;

// Recruiting (specs/grow/17).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('recruiting')->name('recruiting.')->group(function (): void {
    Route::get('/', [RecruitingController::class, 'index'])->name('index');
    Route::post('/', [RecruitingController::class, 'store'])->name('store');
    Route::get('agents', [RecruitingController::class, 'agents'])->name('agents');
    Route::post('agents/{agent}/recruit', [RecruitingController::class, 'recruitAgent'])->whereNumber('agent')->name('agents.recruit');
    Route::post('settings', [RecruitingController::class, 'settings'])->name('settings');
    Route::get('{recruit}', [RecruitingController::class, 'show'])->name('show');
    Route::put('{recruit}', [RecruitingController::class, 'update'])->name('update');
    Route::post('{recruit}/stage', [RecruitingController::class, 'stage'])->name('stage');
    Route::post('{recruit}/note', [RecruitingController::class, 'note'])->middleware('throttle:60,1')->name('note');
    Route::post('{recruit}/plan', [RecruitingController::class, 'plan'])->name('plan');
});
