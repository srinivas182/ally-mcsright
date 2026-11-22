<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\TryController;
use Modules\Marketing\Content\Publisher;
use Modules\Marketing\Http\SignInController;
use Modules\Marketing\Http\SiteController;

/*
| SG1 (specs/marketing/01): RightAlly's public website. No session or cookies, so pages are cacheable at the edge.
| Every host in marketing.hosts serves it; hosts other than the first redirect to the first (one canonical address).
*/
$slug = '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)?';
foreach ((array) config('marketing.hosts') as $i => $host) {
    $n = $i === 0 ? '' : '.'.$i;
    Route::domain((string) $host)->middleware('throttle:240,1')->group(function () use ($slug, $n): void {
        Route::get('sitemap.xml', [SiteController::class, 'sitemap'])->name('marketing.sitemap'.$n);
        Route::get('robots.txt', [SiteController::class, 'robots'])->name('marketing.robots'.$n);
        Route::get('llms.txt', [SiteController::class, 'llms'])->name('marketing.llms'.$n);
        // MK3: the IndexNow key, so search engines accept "this page is new" notices from RightAlly.
        Route::get('indexnow-key.txt', fn () => response(Publisher::indexNowKey(), 200, ['Content-Type' => 'text/plain']))->name('marketing.indexnow'.$n);
        Route::get('preview/{ulid}', [SiteController::class, 'preview'])->where('ulid', '[0-9A-HJKMNP-TV-Z]{26}')->name('marketing.preview'.$n);
        Route::get('blog', [SiteController::class, 'blog'])->defaults('locale', 'en')->name('marketing.blog'.$n);
        Route::get('es/blog', [SiteController::class, 'blog'])->defaults('locale', 'es')->name('marketing.blog.es'.$n);
        Route::get('blog/{slug}', [SiteController::class, 'post'])->defaults('locale', 'en')->where('slug', '[a-z0-9-]{1,100}')->name('marketing.post'.$n);
        Route::get('es/blog/{slug}', [SiteController::class, 'post'])->defaults('locale', 'es')->where('slug', '[a-z0-9-]{1,100}')->name('marketing.post.es'.$n);
        // BR1: "Log in" asks for the brokerage's own address and sends the person there.
        Route::get('login', [SignInController::class, 'show'])->name('marketing.login'.$n);
        Route::get('sign-in', fn () => redirect('/login', 301))->name('marketing.sign-in'.$n);
        Route::get('login/go', [SignInController::class, 'go'])->middleware('throttle:10,1')->name('marketing.login.go'.$n);
        // MK2: "Try it now" with made-up clients (off unless DEMO_ENABLED).
        Route::get('try', [TryController::class, 'start'])->middleware('throttle:5,60')->name('marketing.try'.$n);
        Route::get('/', [SiteController::class, 'page'])->defaults('locale', 'en')->defaults('slug', '')->name('marketing.home'.$n);
        Route::get('es', [SiteController::class, 'page'])->defaults('locale', 'es')->defaults('slug', '')->name('marketing.home.es'.$n);
        Route::get('es/{slug}', [SiteController::class, 'page'])->defaults('locale', 'es')->where('slug', $slug)->name('marketing.page.es'.$n);
        Route::get('{slug}', [SiteController::class, 'page'])->defaults('locale', 'en')->where('slug', $slug)->name('marketing.page'.$n);
    });
}
