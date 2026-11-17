<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Recruiting\Http\AttractionController;
use Modules\Recruiting\Http\RecruitingController;

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
    Route::get('{recruit}', [RecruitingController::class, 'show'])->name('show');
    Route::put('{recruit}', [RecruitingController::class, 'update'])->name('update');
    Route::post('{recruit}/stage', [RecruitingController::class, 'stage'])->name('stage');
    Route::post('{recruit}/note', [RecruitingController::class, 'note'])->middleware('throttle:60,1')->name('note');
    Route::post('{recruit}/plan', [RecruitingController::class, 'plan'])->name('plan');
    Route::post('{recruit}/campaign', [AttractionController::class, 'start'])->name('campaign.start');
    Route::post('{recruit}/campaign/{campaign}/stop', [AttractionController::class, 'stop'])->name('campaign.stop');
});

// One-click unsubscribe from recruiting emails (signed; no session, so mail apps can POST it).
Route::middleware(['tenant', EnsureTenantWorkspace::class, 'throttle:30,1,recruiting-stop'])
    ->match(['get', 'post'], 'recruiting-stop/{recruit}', [AttractionController::class, 'unsubscribe'])->name('recruiting.unsubscribe');
