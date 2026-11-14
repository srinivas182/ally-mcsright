<?php

declare(strict_types=1);

namespace Modules\Sites\Http;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Sites\Http\Controllers\SiteController;
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
        $r->get('{path?}', [SiteController::class, 'page'])->where('path', '[A-Za-z0-9/_\-]*');
    }

    public function dispatch(Request $request): Response
    {
        return $this->router->dispatch($request);
    }
}
