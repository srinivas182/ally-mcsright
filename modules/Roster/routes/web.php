<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Roster\Http\RosterController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('roster')->name('roster.')->group(function (): void {
    Route::get('/', [RosterController::class, 'index'])->name('index');
    Route::post('settings', [RosterController::class, 'settings'])->middleware('password.confirm')->name('settings');
    Route::post('deals/{deal}/override', [RosterController::class, 'override'])->middleware('password.confirm')->name('override');
    Route::post('credentials/{credential}/verify', [RosterController::class, 'verify'])->name('verify');
    Route::get('documents/{document}', [RosterController::class, 'download'])->name('download');
    Route::get('{agent}', [RosterController::class, 'show'])->name('show');
    Route::post('{agent}/credentials', [RosterController::class, 'add'])->name('add');
    Route::post('{agent}/documents', [RosterController::class, 'upload'])->middleware('throttle:30,1')->name('upload');
});
