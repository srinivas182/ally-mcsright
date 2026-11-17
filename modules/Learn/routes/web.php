<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Learn\Http\LearnAdminController;
use Modules\Learn\Http\LearnController;
use Modules\Learn\Http\LearnIqController;

// Learn (LN1, specs/learn/01).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('learn')->name('learn.')->group(function (): void {
    Route::get('/', [LearnController::class, 'index'])->name('index');
    Route::get('courses/{course}', [LearnController::class, 'course'])->name('course');
    Route::post('courses/{course}/start', [LearnController::class, 'start'])->name('start');
    Route::post('courses/{course}/lessons/{lesson}/done', [LearnController::class, 'done'])->whereNumber('lesson')->name('done');
    Route::post('courses/{course}/lessons/{lesson}/quiz', [LearnController::class, 'quiz'])->whereNumber('lesson')->middleware('throttle:30,1')->name('quiz');
    Route::get('courses/{course}/certificate.pdf', [LearnController::class, 'certificate'])->name('certificate');
    Route::get('kb/{article}', [LearnController::class, 'article'])->name('article');
    Route::post('kb/{article}/acknowledge', [LearnController::class, 'acknowledge'])->name('acknowledge');

    // Learn-IQ (LN2, specs/learn/02).
    Route::get('launch', [LearnIqController::class, 'launch'])->name('launch');
    Route::post('launch', [LearnIqController::class, 'start'])->name('launch.start');
    Route::post('launch/{launch}/steps/{step}', [LearnIqController::class, 'tick'])->whereNumber('launch')->where('step', '[a-z_]{2,30}')->name('launch.tick');
    Route::post('mentor', [LearnIqController::class, 'mentor'])->name('mentor');
    Route::post('manage/gates', [LearnIqController::class, 'gate'])->name('gates');
    Route::post('manage/build', [LearnIqController::class, 'build'])->middleware('throttle:10,1')->name('build');

    Route::get('manage', [LearnAdminController::class, 'index'])->name('manage');
    Route::get('manage/report.csv', [LearnAdminController::class, 'report'])->name('report');
    Route::get('manage/courses/new', [LearnAdminController::class, 'edit'])->name('courses.new');
    Route::post('manage/courses', [LearnAdminController::class, 'save'])->name('courses.store');
    Route::get('manage/courses/{course}', [LearnAdminController::class, 'edit'])->name('courses.edit');
    Route::post('manage/courses/{course}', [LearnAdminController::class, 'save'])->name('courses.update');
    Route::post('manage/courses/{course}/assign', [LearnAdminController::class, 'assign'])->name('courses.assign');
    Route::post('manage/starters/{key}', [LearnAdminController::class, 'starter'])->where('key', '[a-z0-9-]{2,60}')->name('starters.add');
    Route::get('manage/kb/new', [LearnAdminController::class, 'editArticle'])->name('kb.new');
    Route::post('manage/kb', [LearnAdminController::class, 'article'])->name('kb.store');
    Route::get('manage/kb/{article}', [LearnAdminController::class, 'editArticle'])->name('kb.edit');
    Route::post('manage/kb/{article}', [LearnAdminController::class, 'article'])->name('kb.update');
});
