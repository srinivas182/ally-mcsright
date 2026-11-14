<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Crm\Http\Controllers\ActivityController;
use Modules\Crm\Http\Controllers\AskController;
use Modules\Crm\Http\Controllers\BriefController;
use Modules\Crm\Http\Controllers\ContactController;
use Modules\Crm\Http\Controllers\DataController;
use Modules\Crm\Http\Controllers\Inbox\InboxController;
use Modules\Crm\Http\Controllers\Inbox\TwilioSmsController;
use Modules\Crm\Http\Controllers\Leads\InboundEmailController;
use Modules\Crm\Http\Controllers\Leads\LeadApiController;
use Modules\Crm\Http\Controllers\Leads\LeadConnectController;
use Modules\Crm\Http\Controllers\Leads\LeadController;
use Modules\Crm\Http\Controllers\Leads\LeadFormController;
use Modules\Crm\Http\Controllers\PipelineController;
use Modules\Crm\Http\Controllers\PlanController;
use Modules\Crm\Http\Controllers\RelationshipController;
use Modules\Crm\Http\Controllers\SavedSearchController;
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
        Route::post('{contact}/searches', [SavedSearchController::class, 'store'])->name('searches.store');
        Route::post('{contact}/send-listing', [SavedSearchController::class, 'sendListing'])->middleware('throttle:30,1,send-listing')->name('send-listing');
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

// Leads (specs/grow/04, Sprint 16A).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.basic'])
    ->prefix('leads')
    ->name('crm.leads.')
    ->group(function (): void {
        Route::get('/', [LeadController::class, 'index'])->name('index');
        Route::post('alerts', [LeadController::class, 'alerts'])->name('alerts');
        Route::get('settings', [LeadController::class, 'settings'])->name('settings');
        Route::post('settings', [LeadController::class, 'saveSettings'])->name('settings.save');
        Route::post('ai', [LeadController::class, 'saveAi'])->name('ai.save');
        Route::get('report', [LeadController::class, 'report'])->name('report');
        Route::post('report/spend', [LeadController::class, 'saveSpend'])->name('report.spend');
        Route::post('report/targets', [LeadController::class, 'saveTargets'])->name('report.targets');
        Route::post('sources', [LeadController::class, 'storeSource'])->name('sources.store');
        Route::post('sources/{source}/active', [LeadController::class, 'sourceActive'])->name('sources.active');
        Route::post('sources/{source}/key', [LeadController::class, 'rotateKey'])->middleware('password.confirm')->name('sources.key');
        Route::post('rules', [LeadController::class, 'storeRule'])->name('rules.store');
        Route::post('rules/order', [LeadController::class, 'orderRules'])->name('rules.order');
        Route::patch('rules/{rule}', [LeadController::class, 'updateRule'])->name('rules.update');
        Route::delete('rules/{rule}', [LeadController::class, 'destroyRule'])->name('rules.destroy');
        Route::post('{lead}/claim', [LeadController::class, 'claim'])->middleware('throttle:30,1,lead-claim')->name('claim');
    });

// Public lead intake: the lead API, hosted and embedded forms, and the speed-to-lead call (no session, no CSRF).
Route::middleware([EnsureTenantWorkspace::class, 'throttle:120,1,lead-api'])->post('api/leads', [LeadApiController::class, 'store'])->name('crm.leads.api');
Route::middleware([EnsureTenantWorkspace::class, 'throttle:60,1,lead-form-view'])->group(function (): void {
    Route::get('l/{token}', [LeadFormController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('crm.leads.form');
    Route::get('l/{token}/thanks', [LeadFormController::class, 'thanks'])->where('token', '[A-Za-z0-9]{40}')->name('crm.leads.thanks');
});
Route::middleware([EnsureTenantWorkspace::class, 'throttle:10,1,lead-form'])->post('l/{token}', [LeadFormController::class, 'store'])->where('token', '[A-Za-z0-9]{40}')->name('crm.leads.form.store');
Route::middleware([EnsureTenantWorkspace::class, 'throttle:60,1,lead-connect'])->post('webhooks/leads/connect/{lead}/{agent}', LeadConnectController::class)
    ->where('agent', '[0-9]+')->name('crm.leads.connect');
Route::middleware('throttle:600,1,inbound-email')->post('webhooks/inbound/lead-email', InboundEmailController::class)->name('crm.leads.inbound');

// Unified inbox (specs/grow/05, Sprint 16A-2).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.basic'])
    ->prefix('inbox')
    ->name('crm.inbox.')
    ->group(function (): void {
        Route::get('/', [InboxController::class, 'index'])->name('index');
        Route::get('start/{contact}', [InboxController::class, 'start'])->name('start');
        Route::post('templates', [InboxController::class, 'storeTemplate'])->name('templates.store');
        Route::delete('templates/{template}', [InboxController::class, 'destroyTemplate'])->name('templates.destroy');
        Route::post('signs', [InboxController::class, 'storeSign'])->name('signs.store');
        Route::post('signs/{sign}/toggle', [InboxController::class, 'toggleSign'])->name('signs.toggle');
        Route::post('{conversation}/send', [InboxController::class, 'send'])->middleware('throttle:60,1,inbox-send')->name('send');
        Route::post('{conversation}/draft', [InboxController::class, 'draft'])->middleware(['feature:crm.ai', 'throttle:20,1,inbox-draft'])->name('draft');
        Route::post('{conversation}/assign', [InboxController::class, 'assign'])->name('assign');
    });
Route::middleware('throttle:600,1,twilio-sms')->post('webhooks/twilio/sms', TwilioSmsController::class)->name('crm.inbox.twilio');

// Saved searches (specs/grow/08).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.basic'])->group(function (): void {
    Route::patch('searches/{search}', [SavedSearchController::class, 'update'])->name('crm.searches.update');
    Route::delete('searches/{search}', [SavedSearchController::class, 'destroy'])->name('crm.searches.destroy');
});
Route::middleware([EnsureTenantWorkspace::class, 'throttle:30,1,alert-stop'])->match(['get', 'post'], 'alerts/{search}/stop', [SavedSearchController::class, 'stop'])->name('crm.alerts.stop');
