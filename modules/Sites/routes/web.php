<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Sites\Http\Controllers\BlogAdminController;
use Modules\Sites\Http\Controllers\HqSitesController;
use Modules\Sites\Http\Controllers\MediaController;
use Modules\Sites\Http\Controllers\PageEditorController;
use Modules\Sites\Http\Controllers\WebsiteSettingsController;

// Settings → Website (owners and staff; specs/websites/05). The public site itself is served by ServeWebsite.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])
    ->prefix('settings/website')
    ->name('settings.website.')
    ->group(function (): void {
        Route::get('/', [WebsiteSettingsController::class, 'index'])->name('index');
        Route::post('/', [WebsiteSettingsController::class, 'store'])->name('store');
        Route::patch('/', [WebsiteSettingsController::class, 'update'])->name('update');
        Route::post('publish', [WebsiteSettingsController::class, 'publish'])->name('publish');
        Route::patch('pages/{page}', [WebsiteSettingsController::class, 'page'])->name('pages.update');
        Route::post('pages/{page}/preview', [WebsiteSettingsController::class, 'preview'])->middleware('throttle:30,1,site-preview')->name('pages.preview');
        Route::post('domains', [WebsiteSettingsController::class, 'domain'])->middleware('throttle:10,1,site-domain')->name('domains.store');
        Route::post('menus', [WebsiteSettingsController::class, 'menus'])->name('menus');
        Route::post('redirects', [WebsiteSettingsController::class, 'redirect'])->name('redirects.store');
        Route::post('redirects/import', [WebsiteSettingsController::class, 'importRedirects'])->name('redirects.import');
        Route::delete('redirects/{redirect}', [WebsiteSettingsController::class, 'deleteRedirect'])->whereNumber('redirect')->name('redirects.destroy');

        // Page builder.
        Route::post('pages', [PageEditorController::class, 'store'])->name('pages.store');
        Route::get('pages/blank', [PageEditorController::class, 'blank'])->name('pages.blank');
        Route::get('pages/{page}/edit', [PageEditorController::class, 'edit'])->name('pages.edit');
        Route::put('pages/{page}/draft', [PageEditorController::class, 'draft'])->middleware('throttle:120,1,site-draft')->name('pages.draft');
        Route::post('pages/{page}/publish', [PageEditorController::class, 'publish'])->name('pages.publish');
        Route::post('pages/{page}/discard', [PageEditorController::class, 'discard'])->name('pages.discard');
        Route::post('pages/{page}/restore', [PageEditorController::class, 'restore'])->name('pages.restore');
        Route::delete('pages/{page}', [PageEditorController::class, 'destroy'])->name('pages.destroy');

        // Media library.
        Route::get('media', [MediaController::class, 'index'])->name('media.index');
        Route::post('media', [MediaController::class, 'store'])->middleware('throttle:60,1,site-media')->name('media.store');
        Route::patch('media/{media}', [MediaController::class, 'update'])->name('media.update');
        Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

        // Blog.
        Route::get('blog', [BlogAdminController::class, 'index'])->name('blog.index');
        Route::get('blog/new', [BlogAdminController::class, 'edit'])->name('blog.create');
        Route::post('blog', [BlogAdminController::class, 'store'])->name('blog.store');
        Route::post('blog/check', [BlogAdminController::class, 'check'])->middleware('throttle:120,1,site-check')->name('blog.check');
        Route::get('blog/{post}', [BlogAdminController::class, 'edit'])->name('blog.edit');
        Route::put('blog/{post}', [BlogAdminController::class, 'update'])->name('blog.update');
        Route::delete('blog/{post}', [BlogAdminController::class, 'destroy'])->name('blog.destroy');
    });

// HQ → Websites: the "Powered by RightAlly" credit and every brokerage's site.
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.sites.manage'])
    ->prefix('hq/sites')
    ->name('hq.sites.')
    ->group(function (): void {
        Route::get('/', [HqSitesController::class, 'index'])->name('index');
        Route::post('powered-by', [HqSitesController::class, 'powered'])->name('powered');
        Route::post('maps', [HqSitesController::class, 'maps'])->name('maps');
        Route::post('powered-by/{tenant}', [HqSitesController::class, 'tenant'])->name('powered.tenant');
    });
