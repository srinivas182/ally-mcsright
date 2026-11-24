<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Recruiting\Http\AttractionController;
use Modules\Recruiting\Http\GrowthController;
use Modules\Recruiting\Http\JoinController;
use Modules\Recruiting\Http\RecruitingController;

// CM4: join through an agent's link; licence checked, pack paid, placed under the sponsor (no approval).
Route::middleware(['web', EnsureTenantWorkspace::class])->group(function (): void {
    Route::get('join/done', [JoinController::class, 'done'])->name('join.done');
    Route::get('join/{code?}', [JoinController::class, 'show'])->where('code', '[a-z0-9]{6,12}')->middleware('throttle:60,1')->name('join');
    Route::post('join/{code?}', [JoinController::class, 'store'])->where('code', '[a-z0-9]{6,12}')->middleware('throttle:6,60')->name('join.store');
});

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::get('recruiting/join-page', [JoinController::class, 'edit'])->name('join.page');
    Route::post('recruiting/join-page', [JoinController::class, 'save'])->name('join.page.save');
});

// Recruiting (specs/grow/17).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('recruiting')->name('recruiting.')->group(function (): void {
    Route::get('/', [RecruitingController::class, 'index'])->name('index');
    Route::post('/', [RecruitingController::class, 'store'])->name('store');
    Route::get('agents', [RecruitingController::class, 'agents'])->name('agents');
    Route::post('agents/{agent}/recruit', [RecruitingController::class, 'recruitAgent'])->whereNumber('agent')->name('agents.recruit');
    Route::post('settings', [RecruitingController::class, 'settings'])->name('settings');
    Route::get('campaigns', [AttractionController::class, 'campaigns'])->name('campaigns');
    Route::post('campaigns', [AttractionController::class, 'saveCampaign'])->name('campaigns.store');
    Route::put('campaigns/{campaign}', [AttractionController::class, 'saveCampaign'])->name('campaigns.update');
    Route::post('campaigns/{campaign}/status', [AttractionController::class, 'campaignStatus'])->name('campaigns.status');
    Route::get('careers', [AttractionController::class, 'careers'])->name('careers');
    Route::post('careers', [AttractionController::class, 'saveCareers'])->name('careers.save');
    Route::get('my-story', [AttractionController::class, 'myStory'])->name('story');
    Route::post('my-story', [AttractionController::class, 'saveStory'])->name('story.save');
    Route::delete('my-story', [AttractionController::class, 'removeStory'])->name('story.remove');
    Route::get('refer', [GrowthController::class, 'refer'])->name('refer');
    Route::post('refer', [GrowthController::class, 'storeReferral'])->middleware('throttle:20,1')->name('refer.store');
    Route::post('refer/draft', [GrowthController::class, 'draft'])->middleware(['throttle:10,1', 'feature:crm.ai'])->name('refer.draft');
    Route::post('refer/database', [GrowthController::class, 'databaseForAgents'])->name('refer.database');
    Route::get('retention', [GrowthController::class, 'retention'])->name('retention');
    Route::post('retention/{agent}', [GrowthController::class, 'checkIn'])->name('retention.checkin');
    Route::get('results', [GrowthController::class, 'results'])->name('results');
    Route::post('results/spend', [GrowthController::class, 'spend'])->name('results.spend');
    Route::get('{recruit}', [RecruitingController::class, 'show'])->name('show');
    Route::put('{recruit}', [RecruitingController::class, 'update'])->name('update');
    Route::post('{recruit}/stage', [RecruitingController::class, 'stage'])->name('stage');
    Route::post('{recruit}/note', [RecruitingController::class, 'note'])->middleware('throttle:60,1')->name('note');
    Route::post('{recruit}/plan', [RecruitingController::class, 'plan'])->name('plan');
    Route::post('{recruit}/campaign', [AttractionController::class, 'start'])->name('campaign.start');
    Route::post('{recruit}/invite', [GrowthController::class, 'invite'])->name('invite');
    Route::post('{recruit}/campaign/{campaign}/stop', [AttractionController::class, 'stop'])->name('campaign.stop');
});

// One-click unsubscribe from recruiting emails (signed; no session, so mail apps can POST it).
Route::middleware(['tenant', EnsureTenantWorkspace::class, 'throttle:30,1,recruiting-stop'])
    ->match(['get', 'post'], 'recruiting-stop/{recruit}', [AttractionController::class, 'unsubscribe'])->name('recruiting.unsubscribe');
