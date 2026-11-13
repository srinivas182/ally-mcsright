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
    Route::post('agreements', [RosterController::class, 'publishAgreement'])->name('agreements.publish');
    Route::post('agreements/{kind}/sign', [RosterController::class, 'sign'])->whereIn('kind', ['agreement', 'policy'])->middleware('throttle:10,1')->name('agreements.sign');
    Route::post('tasks/{task}', [RosterController::class, 'markTask'])->whereNumber('task')->name('tasks.mark');
    Route::post('me/requests', [RosterController::class, 'submitRequest'])->middleware('throttle:10,1')->name('requests.submit');
    Route::post('requests/{update}/decide', [RosterController::class, 'decideRequest'])->name('requests.decide');
    Route::post('ce/rules', [RosterController::class, 'ceRule'])->name('ce.rule');
    Route::post('ce/courses/{course}/remove', [RosterController::class, 'ceRemove'])->name('ce.remove');
    Route::post('credentials/{credential}/check', [RosterController::class, 'check'])->middleware('throttle:30,1')->name('check');
    Route::get('export.csv', [RosterController::class, 'export'])->name('export');
    Route::get('{agent}', [RosterController::class, 'show'])->name('show');
    Route::post('{agent}/ce', [RosterController::class, 'ceLog'])->middleware('throttle:30,1')->name('ce.log');
    Route::post('{agent}/flows', [RosterController::class, 'startFlow'])->name('flows.start');
    Route::post('{agent}/flows/{kind}/complete', [RosterController::class, 'completeFlow'])->middleware('password.confirm')->name('flows.complete');
    Route::post('{agent}/credentials', [RosterController::class, 'add'])->name('add');
    Route::post('{agent}/documents', [RosterController::class, 'upload'])->middleware('throttle:30,1')->name('upload');
});

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->get('directory', [RosterController::class, 'directory'])->name('directory');
