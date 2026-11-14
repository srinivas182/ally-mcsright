<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Sites\Http\Controllers\HqSitesController;
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
    });

// HQ → Websites: the "Powered by RightAlly" credit and every brokerage's site.
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.sites.manage'])
    ->prefix('hq/sites')
    ->name('hq.sites.')
    ->group(function (): void {
        Route::get('/', [HqSitesController::class, 'index'])->name('index');
        Route::post('powered-by', [HqSitesController::class, 'powered'])->name('powered');
        Route::post('powered-by/{tenant}', [HqSitesController::class, 'tenant'])->name('powered.tenant');
    });
