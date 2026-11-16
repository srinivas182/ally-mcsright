<?php

declare(strict_types=1);

namespace Modules\Movewise\Http;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Movewise\Http\Controllers\SiteController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Movewise's public routes (specs/movewise/01): kept apart from the back office (no session, no CSRF, cacheable). */
final class MovewiseRouter
{
    private Router $router;

    public function __construct(Dispatcher $events, Container $container)
    {
        $this->router = new Router($events, $container);
        $r = $this->router;
        $r->get('/', [SiteController::class, 'home']);
        $r->get('robots.txt', [SiteController::class, 'robots']);
        $r->get('sitemap.xml', [SiteController::class, 'sitemap']);
        $r->get('sitemaps/{state}.xml', [SiteController::class, 'stateSitemap'])->where('state', '[a-z]{2}|pages');
        $r->get('homes', [SiteController::class, 'search']);
        $r->get('homes/{listing}/{slug?}', [SiteController::class, 'listing'])->where(['listing' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'slug' => '[a-z0-9-]{0,140}']);
        $r->get('{page}', [SiteController::class, 'page'])->where('page', 'how-movewise-is-paid|privacy|terms|fair-housing|brokers|accessibility');
        $r->get('{state}', [SiteController::class, 'state'])->where('state', '[a-z-]{4,20}');
    }

    public function dispatch(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return response()->view('movewise::missing', ['site' => app(SiteController::class)->chrome()], 404)->header('Cache-Control', 'public, max-age=60');
        }
    }
}
