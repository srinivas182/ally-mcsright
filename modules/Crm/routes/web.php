<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Crm\Http\Controllers\ActivityController;
use Modules\Crm\Http\Controllers\BriefController;
use Modules\Crm\Http\Controllers\ContactController;
use Modules\Crm\Http\Controllers\DataController;
use Modules\Crm\Http\Controllers\PipelineController;
use Modules\Crm\Http\Controllers\TaskController;
use Modules\Crm\Http\Controllers\WorkListController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])
    ->prefix('contacts')
    ->name('crm.contacts.')
    ->group(function (): void {
        Route::get('/', [ContactController::class, 'index'])->name('index');
        Route::get('board', [PipelineController::class, 'board'])->name('board');
        Route::get('duplicates', [DataController::class, 'duplicates'])->name('duplicates');
        Route::get('work', [WorkListController::class, 'show'])->name('work');
        Route::post('merge', [DataController::class, 'merge'])->name('merge');
        Route::post('imports', [DataController::class, 'upload'])->name('imports.store');
        Route::post('/', [ContactController::class, 'store'])->name('store');
        Route::get('{contact}', [ContactController::class, 'show'])->name('show');
        Route::patch('{contact}', [ContactController::class, 'update'])->name('update');
        Route::post('{contact}/assign', [ContactController::class, 'assign'])->name('assign');
        Route::post('{contact}/relationships', [ContactController::class, 'relate'])->name('relate');
        Route::post('{contact}/activities', [ActivityController::class, 'store'])->name('activities.store');
        Route::post('{contact}/stage', [PipelineController::class, 'move'])->name('stage');
        Route::post('{contact}/consent', [DataController::class, 'consent'])->name('consent');
        Route::post('{contact}/brief', [BriefController::class, 'refresh'])->middleware('throttle:20,1')->name('brief.refresh');
        Route::post('{contact}/brief/feedback', [BriefController::class, 'feedback'])->name('brief.feedback');
        Route::post('{contact}/tags', [PipelineController::class, 'tag'])->name('tags.store');
        Route::delete('{contact}/tags/{tag}', [PipelineController::class, 'untag'])->name('tags.destroy');
    });

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::post('activities/{activity}/pin', [ActivityController::class, 'pin'])->name('crm.activities.pin');
    Route::get('contact-imports/{import}', [DataController::class, 'show'])->name('crm.imports.show');
    Route::post('contact-imports/{import}/run', [DataController::class, 'run'])->name('crm.imports.run');
    Route::post('smart-lists', [PipelineController::class, 'saveList'])->name('crm.lists.store');
    Route::delete('smart-lists/{list}', [PipelineController::class, 'deleteList'])->name('crm.lists.destroy');
    Route::post('pipelines/{pipeline}/stages', [PipelineController::class, 'addStage'])->name('crm.stages.store');
    Route::post('pipelines/{pipeline}/reorder', [PipelineController::class, 'reorder'])->name('crm.stages.reorder');
    Route::patch('stages/{stage}', [PipelineController::class, 'renameStage'])->name('crm.stages.update');
    Route::delete('stages/{stage}', [PipelineController::class, 'deleteStage'])->name('crm.stages.destroy');
    Route::get('tasks', [TaskController::class, 'index'])->name('crm.tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('crm.tasks.store');
    Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->name('crm.tasks.complete');
    Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('crm.tasks.reopen');
    Route::post('tasks/{task}/reschedule', [TaskController::class, 'reschedule'])->name('crm.tasks.reschedule');
});
