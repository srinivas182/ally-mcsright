<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Crm\Http\Controllers\ActivityController;
use Modules\Crm\Http\Controllers\ContactController;
use Modules\Crm\Http\Controllers\TaskController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])
    ->prefix('contacts')
    ->name('crm.contacts.')
    ->group(function (): void {
        Route::get('/', [ContactController::class, 'index'])->name('index');
        Route::post('/', [ContactController::class, 'store'])->name('store');
        Route::get('{contact}', [ContactController::class, 'show'])->name('show');
        Route::patch('{contact}', [ContactController::class, 'update'])->name('update');
        Route::post('{contact}/assign', [ContactController::class, 'assign'])->name('assign');
        Route::post('{contact}/relationships', [ContactController::class, 'relate'])->name('relate');
        Route::post('{contact}/activities', [ActivityController::class, 'store'])->name('activities.store');
    });

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::post('activities/{activity}/pin', [ActivityController::class, 'pin'])->name('crm.activities.pin');
    Route::get('tasks', [TaskController::class, 'index'])->name('crm.tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('crm.tasks.store');
    Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->name('crm.tasks.complete');
    Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('crm.tasks.reopen');
    Route::post('tasks/{task}/reschedule', [TaskController::class, 'reschedule'])->name('crm.tasks.reschedule');
});
