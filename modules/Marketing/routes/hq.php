<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
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
