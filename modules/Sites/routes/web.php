<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Sites\Http\Controllers\AillyController;
use Modules\Sites\Http\Controllers\AillyLiveController;
use Modules\Sites\Http\Controllers\AreaPagesController;
use Modules\Sites\Http\Controllers\BlogAdminController;
use Modules\Sites\Http\Controllers\DemandController;
use Modules\Sites\Http\Controllers\DesignPreviewController;
use Modules\Sites\Http\Controllers\FunnelAdminController;
use Modules\Sites\Http\Controllers\HomeDashboardController;
use Modules\Sites\Http\Controllers\HomeReportController;
use Modules\Sites\Http\Controllers\HqSitesController;
use Modules\Sites\Http\Controllers\MediaController;
use Modules\Sites\Http\Controllers\MyWebsiteController;
use Modules\Sites\Http\Controllers\PageEditorController;
use Modules\Sites\Http\Controllers\ReviewsController;
use Modules\Sites\Http\Controllers\StudioController;
use Modules\Sites\Http\Controllers\WebsiteSettingsController;

// Settings → Website (owners and staff; specs/websites/05). The public site itself is served by ServeWebsite.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->group(function (): void {
    Route::get('settings/ailly', [AillyController::class, 'settings'])->name('settings.ailly');
    // C8a: reviews from Google and RealSatisfied.
    Route::get('reviews', [ReviewsController::class, 'index'])->name('reviews');
    Route::post('reviews/{review}/reply', [ReviewsController::class, 'reply'])->where('review', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:30,1')->name('reviews.reply');
    Route::post('reviews/{review}/hide', [ReviewsController::class, 'hide'])->where('review', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('reviews.hide');
    Route::post('reviews/{review}/show', [ReviewsController::class, 'show'])->where('review', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('reviews.show');
    Route::post('settings/ailly', [AillyController::class, 'save'])->name('settings.ailly.save');
    Route::post('settings/ailly/answers', [AillyController::class, 'saveAnswer'])->name('settings.ailly.answers');
    Route::delete('settings/ailly/answers/{answer}', [AillyController::class, 'deleteAnswer'])->whereNumber('answer')->name('settings.ailly.answers.delete');
    Route::post('settings/ailly/questions/{question}/dismiss', [AillyController::class, 'dismissQuestion'])->whereNumber('question')->name('settings.ailly.questions.dismiss');
    Route::get('settings/ailly/audit', [AillyController::class, 'audit'])->middleware('throttle:20,1')->name('settings.ailly.audit');
    Route::post('settings/ailly/test', [AillyController::class, 'startTest'])->name('settings.ailly.test');
    Route::post('settings/ailly/test/stop', [AillyController::class, 'stopTest'])->name('settings.ailly.test.stop');
    Route::get('listings/demand', [DemandController::class, 'index'])->name('demand.index');
    Route::post('listings/demand', [DemandController::class, 'store'])->middleware('throttle:20,1')->name('demand.store');
    Route::get('listings/demand/{report}/print', [DemandController::class, 'print'])->where('report', '[0-9A-HJKMNP-TV-Z]{26}')->name('demand.print');
    Route::post('listings/demand/{report}/tell', [DemandController::class, 'tell'])->where('report', '[0-9A-HJKMNP-TV-Z]{26}')->name('demand.tell');
    Route::get('funnels', [FunnelAdminController::class, 'index'])->name('funnels.index');
    Route::post('funnels', [FunnelAdminController::class, 'store'])->middleware('throttle:20,1')->name('funnels.store');
    Route::patch('funnels/{funnel}', [FunnelAdminController::class, 'update'])->where('funnel', '[0-9A-HJKMNP-TV-Z]{26}')->name('funnels.update');
    Route::get('ailly/chats', [AillyLiveController::class, 'index'])->name('ailly.chats');
    Route::get('ailly/chats/{chat}', [AillyLiveController::class, 'show'])->where('chat', '[0-9A-Za-z]{26}')->name('ailly.chats.show');
    Route::post('ailly/chats/{chat}/take', [AillyLiveController::class, 'take'])->where('chat', '[0-9A-Za-z]{26}')->name('ailly.chats.take');
    Route::post('ailly/chats/{chat}/say', [AillyLiveController::class, 'say'])->where('chat', '[0-9A-Za-z]{26}')->middleware('throttle:60,1')->name('ailly.chats.say');
    Route::post('ailly/chats/{chat}/hand-back', [AillyLiveController::class, 'handBack'])->where('chat', '[0-9A-Za-z]{26}')->name('ailly.chats.hand-back');
});

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
        Route::post('agents', [WebsiteSettingsController::class, 'agentRules'])->name('agents.rules');
        Route::post('calculators', [WebsiteSettingsController::class, 'calculators'])->name('calculators');
        Route::post('agents/{agent}/publish', [WebsiteSettingsController::class, 'agentPublish'])->name('agents.publish');
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

        // AI content studio.
        Route::post('ai/post', [StudioController::class, 'post'])->middleware('throttle:20,1,site-ai')->name('ai.post');
        Route::post('ai/page', [StudioController::class, 'page'])->middleware('throttle:20,1,site-ai')->name('ai.page');
        Route::post('ai/assist', [StudioController::class, 'assist'])->middleware('throttle:60,1,site-ai-assist')->name('ai.assist');
        Route::post('ai/market-updates', [StudioController::class, 'marketUpdates'])->name('ai.market');

        // IDX9: area pages (cities, neighborhoods and ZIP codes).
        Route::get('areas', [AreaPagesController::class, 'index'])->name('areas.index');
        Route::put('areas/{area}', [AreaPagesController::class, 'update'])->where('area', '[a-z0-9-]{2,140}')->middleware('throttle:60,1')->name('areas.update');

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

// Settings → My website (agents; specs/websites/09).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])
    ->prefix('settings/my-website')
    ->name('settings.my-website.')
    ->group(function (): void {
        Route::get('/', [MyWebsiteController::class, 'index'])->name('index');
        Route::post('/', [MyWebsiteController::class, 'store'])->name('store');
        Route::patch('/', [MyWebsiteController::class, 'update'])->name('update');
        Route::post('publish', [MyWebsiteController::class, 'publish'])->name('publish');
        Route::get('qr.png', [MyWebsiteController::class, 'qr'])->middleware('throttle:30,1')->name('qr');
        Route::post('domains', [MyWebsiteController::class, 'domain'])->middleware('throttle:10,1,agent-domain')->name('domains');
    });

// HQ → Websites: the "Powered by RightAlly" credit and every brokerage's site.
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.sites.manage'])
    ->prefix('hq/sites')
    ->name('hq.sites.')
    ->group(function (): void {
        Route::get('/', [HqSitesController::class, 'index'])->name('index');
        Route::post('powered-by', [HqSitesController::class, 'powered'])->name('powered');
        Route::post('maps', [HqSitesController::class, 'maps'])->name('maps');
        Route::post('maps/secret', [HqSitesController::class, 'mapsSecret'])->middleware('password.confirm')->name('maps.secret');
        Route::post('powered-by/{tenant}', [HqSitesController::class, 'tenant'])->name('powered.tenant');
    });

// The monthly home-value report's one-click stop (specs/grow/03).
Route::middleware(['tenant', EnsureTenantWorkspace::class, 'throttle:30,1,home-report-stop'])->match(['get', 'post'], 'home-reports/{report}/stop', [HomeReportController::class, 'stop'])
    ->where('report', '[0-9A-HJKMNP-TV-Z]{26}')->name('sites.home-reports.stop');
// The homeowner dashboard (MK2, specs/crm/21): private, signed, never indexed.
Route::middleware(['tenant', EnsureTenantWorkspace::class, 'throttle:30,1,home-dashboard'])->match(['get', 'post'], 'my-home/{report}', [HomeDashboardController::class, 'show'])
    ->where('report', '[0-9A-HJKMNP-TV-Z]{26}')->name('sites.home-reports.dashboard');

// SU2: a website design with made-up content, opened from the sign-up's design step (nothing is saved).
Route::domain((string) config('core.hq_host'))->middleware(['web', 'throttle:60,1'])->get('start/designs/{design}', DesignPreviewController::class)
    ->where('design', '[a-z]{2,20}')->name('signup.design');
