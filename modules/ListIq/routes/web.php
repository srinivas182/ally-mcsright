<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\ListIq\Http\FeedbackController;
use Modules\ListIq\Http\ListIqController;

// List-IQ (specs/listings/02).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('list-iq')->name('listiq.')->group(function (): void {
    Route::get('/', [ListIqController::class, 'index'])->name('index');
    Route::post('/', [ListIqController::class, 'store'])->name('store');
    Route::get('{prep}', [ListIqController::class, 'show'])->name('show');
    Route::patch('{prep}', [ListIqController::class, 'update'])->name('update');
    Route::post('{prep}/photos', [ListIqController::class, 'upload'])->middleware('throttle:30,1')->name('photos.upload');
    Route::post('{prep}/photos/order', [ListIqController::class, 'reorder'])->name('photos.order');
    Route::patch('{prep}/photos/{photo}', [ListIqController::class, 'photo'])->name('photos.update');
    Route::post('{prep}/photos/{photo}/enhance', [ListIqController::class, 'enhance'])->middleware('throttle:30,1')->name('photos.enhance');
    Route::post('{prep}/photos/{photo}/stage', [ListIqController::class, 'stage'])->middleware('throttle:20,1')->name('photos.stage');
    Route::post('{prep}/describe', [ListIqController::class, 'write'])->middleware(['throttle:10,1', 'feature:crm.ai'])->name('describe');
    Route::post('{prep}/translate', [ListIqController::class, 'translate'])->middleware(['throttle:10,1', 'feature:crm.ai'])->name('translate');
    Route::get('{prep}/package', [ListIqController::class, 'package'])->middleware('throttle:10,1')->name('package');
    Route::post('{prep}/mls', [ListIqController::class, 'mls'])->name('mls');
    Route::post('{prep}/showings/{showing}', [ListIqController::class, 'decide'])->name('showings.decide');
    Route::post('{prep}/codes', [ListIqController::class, 'addCode'])->middleware('throttle:30,1')->name('codes');
    Route::get('{prep}/codes/{code}', [ListIqController::class, 'qr'])->where('code', '[a-z0-9]{7}')->name('codes.qr');
    Route::post('{prep}/floor-plan', [ListIqController::class, 'floorPlan'])->middleware('throttle:10,1')->name('floor-plan');
    Route::post('{prep}/tour', [ListIqController::class, 'tour'])->middleware('throttle:20,1')->name('tour'); // MD1
    Route::post('{prep}/video', [ListIqController::class, 'video'])->middleware('throttle:5,1')->name('video'); // MD1
    Route::get('{prep}/flyer', [ListIqController::class, 'flyer'])->middleware('throttle:20,1')->name('flyer');
    Route::post('{prep}/open-houses', [ListIqController::class, 'openHouse'])->name('open-houses');
    Route::get('{prep}/open-houses/{openHouse}', [ListIqController::class, 'kiosk'])->name('kiosk');
    Route::post('{prep}/open-houses/{openHouse}/sign-in', [ListIqController::class, 'signIn'])->middleware('throttle:60,1')->name('kiosk.sign-in');
    Route::post('{prep}/open-houses/{openHouse}/end', [ListIqController::class, 'endOpenHouse'])->name('kiosk.end');
});

// Feedback from buyers' agents after a showing (a random link in their email; no sign-in, never indexed).
Route::middleware(['tenant', EnsureTenantWorkspace::class, 'throttle:30,1,showing-feedback'])
    ->match(['get', 'post'], 'showing-feedback/{token}', [FeedbackController::class, 'handle'])->where('token', '[A-Za-z0-9]{40}')->name('listiq.feedback');
