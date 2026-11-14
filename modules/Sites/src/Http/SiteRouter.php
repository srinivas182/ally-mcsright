<?php

declare(strict_types=1);

namespace Modules\Sites\Http;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Sites\Http\Controllers\IdxController;
use Modules\Sites\Http\Controllers\SiteController;
use Modules\Sites\Http\Controllers\ValuationController;
use Modules\Sites\Http\Controllers\VisitorController;
use Symfony\Component\HttpFoundation\Response;

/** The public website's own routes, kept apart from the back office's (no session, no CSRF, cacheable). */
final class SiteRouter
{
    private Router $router;

    public function __construct(Dispatcher $events, Container $container)
    {
        $this->router = new Router($events, $container);
        $r = $this->router;
        $r->get('robots.txt', [SiteController::class, 'robots']);
        $r->get('sitemap.xml', [SiteController::class, 'sitemap']);
        $r->get('llms.txt', [SiteController::class, 'llms']);
        $r->post('_lead', [SiteController::class, 'lead']);
        $r->get('_preview/{page}', [SiteController::class, 'preview'])->where('page', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}');
        $r->get('homes', [IdxController::class, 'search']);
        $r->get('homes/compare', [IdxController::class, 'compare']);
        $r->get('home-value', [ValuationController::class, 'show']);
        $r->post('_valuation', [ValuationController::class, 'submit']);
        $r->post('_t', [VisitorController::class, 'track']);
        $r->post('_auth/start', [VisitorController::class, 'start']);
        $r->post('_auth/verify', [VisitorController::class, 'verify']);
        $r->get('_me', [VisitorController::class, 'show']);
        $r->post('_me/favourites', [VisitorController::class, 'favourite']);
        $r->post('_me/searches', [VisitorController::class, 'saveSearch']);
        $r->get('_search', [IdxController::class, 'results']);
        $r->get('homes/{listing}/{slug?}', [IdxController::class, 'listing'])->where(['listing' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'slug' => '[a-z0-9-]{0,140}']);
        $r->get('market', [IdxController::class, 'markets']);
        $r->get('market/{place}', [IdxController::class, 'market'])->where('place', '[a-z0-9-]{2,80}');
        $r->get('{path?}', [SiteController::class, 'page'])->where('path', '[A-Za-z0-9/_\-]*');
    }

    public function dispatch(Request $request): Response
    {
        return $this->router->dispatch($request);
    }
}
