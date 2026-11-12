<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Crm\Http\Controllers\ActivityController;
use Modules\Crm\Http\Controllers\AskController;
use Modules\Crm\Http\Controllers\BriefController;
use Modules\Crm\Http\Controllers\ContactController;
use Modules\Crm\Http\Controllers\DataController;
use Modules\Crm\Http\Controllers\PipelineController;
use Modules\Crm\Http\Controllers\PlanController;
use Modules\Crm\Http\Controllers\RelationshipController;
use Modules\Crm\Http\Controllers\TaskController;
use Modules\Crm\Http\Controllers\TodayController;
use Modules\Crm\Http\Controllers\WorkListController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.basic'])
    ->prefix('contacts')
    ->name('crm.contacts.')
    ->group(function (): void {
        Route::get('/', [ContactController::class, 'index'])->name('index');
        Route::get('board', [PipelineController::class, 'board'])->name('board');
        Route::get('duplicates', [DataController::class, 'duplicates'])->name('duplicates');
        Route::get('pond', [RelationshipController::class, 'pond'])->name('pond');
        Route::get('lookup', [ContactController::class, 'lookup'])->middleware('throttle:120,1')->name('lookup');
        Route::post('pond/settings', [RelationshipController::class, 'pondSettings'])->name('pond.settings');
        Route::get('work', [WorkListController::class, 'show'])->name('work');
        Route::get('ask', [AskController::class, 'ask'])->middleware('feature:crm.ai')->middleware('throttle:30,1')->name('ask');
        Route::post('quick-add/parse', [AskController::class, 'parseQuickAdd'])->middleware('feature:crm.ai')->middleware('throttle:30,1')->name('quick-add.parse');
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
        Route::post('{contact}/brief', [BriefController::class, 'refresh'])->middleware('feature:crm.ai')->middleware('throttle:20,1')->name('brief.refresh');
        Route::post('{contact}/brief/feedback', [BriefController::class, 'feedback'])->middleware('feature:crm.ai')->name('brief.feedback');
        Route::post('{contact}/voice-log/parse', [AskController::class, 'proposeLog'])->middleware('feature:crm.ai')->middleware('throttle:30,1')->name('voice-log.parse');
        Route::post('{contact}/voice-log', [AskController::class, 'applyLog'])->middleware('feature:crm.ai')->name('voice-log.apply');
        Route::post('{contact}/plans', [PlanController::class, 'enroll'])->name('plans.enroll');
        Route::post('{contact}/claim', [RelationshipController::class, 'claim'])->name('claim');
        Route::post('{contact}/memories', [RelationshipController::class, 'addMemory'])->name('memories.store');
        Route::post('{contact}/referred-by', [RelationshipController::class, 'referredBy'])->name('referred-by');
        Route::post('{contact}/analyse', [TodayController::class, 'analyse'])->middleware('feature:crm.ai')->middleware('throttle:20,1')->name('analyse');
        Route::post('{contact}/tags', [PipelineController::class, 'tag'])->name('tags.store');
        Route::delete('{contact}/tags/{tag}', [PipelineController::class, 'untag'])->name('tags.destroy');
    });

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::post('activities/{activity}/pin', [ActivityController::class, 'pin'])->name('crm.activities.pin');
    Route::delete('memories/{memory}', [RelationshipController::class, 'removeMemory'])->name('crm.memories.destroy');
    Route::get('today', [TodayController::class, 'show'])->middleware('feature:crm.intelligence')->name('crm.today');
    Route::post('intents/{intent}', [TodayController::class, 'intent'])->name('crm.intents.update');
    Route::get('follow-up-plans', [PlanController::class, 'index'])->middleware('feature:backoffice.basic')->name('crm.plans.index');
    Route::post('follow-up-plans', [PlanController::class, 'store'])->name('crm.plans.store');
    Route::put('follow-up-plans/{plan}', [PlanController::class, 'update'])->name('crm.plans.update');
    Route::post('follow-up-plans/{plan}/toggle', [PlanController::class, 'toggle'])->name('crm.plans.toggle');
    Route::post('plan-enrollments/{enrollment}/stop', [PlanController::class, 'stop'])->name('crm.plans.stop');
    Route::post('voice/transcribe', [AskController::class, 'transcribe'])->middleware('feature:crm.ai')->middleware('throttle:30,1')->name('crm.voice.transcribe');
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
