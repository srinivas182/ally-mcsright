<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Marketing\Http\BlogWriterController;
use Modules\Marketing\Http\HqContentController;
use Modules\Marketing\Http\HqWebsiteController;

/* SG1 (specs/marketing/01): editing RightAlly's website from the HQ console. */
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/website')->name('hq.website.')->group(function (): void {
    $ulid = '[0-9A-HJKMNP-TV-Z]{26}';
    Route::get('/', [HqWebsiteController::class, 'index'])->name('index');
    Route::get('pages/new', [HqWebsiteController::class, 'edit'])->name('pages.new');
    Route::get('pages/{page}', [HqWebsiteController::class, 'edit'])->where('page', $ulid)->name('pages.edit');
    Route::post('pages', [HqWebsiteController::class, 'save'])->name('pages.store');
    Route::put('pages/{page}', [HqWebsiteController::class, 'save'])->where('page', $ulid)->name('pages.update');
    Route::post('pages/{page}/publish', [HqWebsiteController::class, 'publish'])->where('page', $ulid)->name('pages.publish');
    Route::post('pages/{page}/unpublish', [HqWebsiteController::class, 'unpublish'])->where('page', $ulid)->name('pages.unpublish');
    Route::post('pages/{page}/restore/{version}', [HqWebsiteController::class, 'restore'])->where('page', $ulid)->whereNumber('version')->name('pages.restore');
    Route::get('posts/new', [HqWebsiteController::class, 'post'])->name('posts.new');
    Route::get('posts/{post}', [HqWebsiteController::class, 'post'])->where('post', $ulid)->name('posts.edit');
    Route::post('posts', [HqWebsiteController::class, 'savePost'])->name('posts.store');
    Route::put('posts/{post}', [HqWebsiteController::class, 'savePost'])->where('post', $ulid)->name('posts.update');
});

/* MK3: the AI content desk — states, levels and the morning review. */
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/content')->name('hq.content.')->group(function (): void {
    $ulid = '[0-9A-HJKMNP-TV-Z]{26}';
    Route::get('/', [HqContentController::class, 'index'])->name('index');
    Route::post('desks', [HqContentController::class, 'saveDesk'])->name('desks');
    Route::post('desks/{desk}/stats', [HqContentController::class, 'manualStats'])->whereNumber('desk')->name('stats');
    Route::post('drafts/{draft}/approve', [HqContentController::class, 'approve'])->where('draft', $ulid)->middleware('throttle:60,1')->name('approve');
    Route::post('drafts/{draft}/reject', [HqContentController::class, 'reject'])->where('draft', $ulid)->name('reject');
});

/* MK4: a brokerage's own AI blog writer (an add-on), in its back office. */
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('settings/blog-writer')->name('settings.blog-writer.')->group(function (): void {
    $ulid = '[0-9A-HJKMNP-TV-Z]{26}';
    Route::get('/', [BlogWriterController::class, 'index'])->name('index');
    Route::post('/', [BlogWriterController::class, 'save'])->middleware('throttle:30,1')->name('save');
    Route::post('drafts/{draft}/approve', [BlogWriterController::class, 'approve'])->where('draft', $ulid)->middleware('throttle:60,1')->name('approve');
    Route::post('drafts/{draft}/reject', [BlogWriterController::class, 'reject'])->where('draft', $ulid)->name('reject');
});
