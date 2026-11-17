<?php

declare(strict_types=1);

namespace Modules\Sites\Http;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Sites\Content\Redirects;
use Modules\Sites\Http\Controllers\AgentSiteController;
use Modules\Sites\Http\Controllers\AillyController;
use Modules\Sites\Http\Controllers\BlogController;
use Modules\Sites\Http\Controllers\FunnelController;
use Modules\Sites\Http\Controllers\IdxController;
use Modules\Sites\Http\Controllers\PrivacyController;
use Modules\Sites\Http\Controllers\SiteController;
use Modules\Sites\Http\Controllers\ValuationController;
use Modules\Sites\Http\Controllers\VisitorController;
use Modules\Sites\Models\Site;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $r->get('privacy', [PrivacyController::class, 'show']);
        $r->post('_valuation', [ValuationController::class, 'submit']);
        $r->get('go/{slug}', [FunnelController::class, 'show'])->where('slug', '[a-z0-9-]{1,80}');
        $r->post('_funnel/{slug}', [FunnelController::class, 'submit'])->where('slug', '[a-z0-9-]{1,80}');
        $r->post('_t', [VisitorController::class, 'track']);
        $r->post('_auth/start', [VisitorController::class, 'start']);
        $r->post('_auth/verify', [VisitorController::class, 'verify']);
        $r->get('_me', [VisitorController::class, 'show']);
        $r->post('_me/favourites', [VisitorController::class, 'favourite']);
        $r->post('_me/searches', [VisitorController::class, 'saveSearch']);
        $r->get('_search', [IdxController::class, 'results']);
        $r->post('_map', [IdxController::class, 'openMap']);
        $r->post('_ailly/start', [AillyController::class, 'start']);
        $r->post('_ailly/message', [AillyController::class, 'message']);
        $r->post('_ailly/contact', [AillyController::class, 'contact']);
        $r->post('_ailly/slots', [AillyController::class, 'slots']);
        $r->post('_ailly/poll', [AillyController::class, 'poll']);
        $r->get('homes/{listing}/{slug?}', [IdxController::class, 'listing'])->where(['listing' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'slug' => '[a-z0-9-]{0,140}']);
        $r->get('blog', [BlogController::class, 'index']);
        $r->get('blog/feed.xml', [BlogController::class, 'feed']);
        $r->get('blog/category/{category}', [BlogController::class, 'category'])->where('category', '[a-z0-9-]{1,60}');
        $r->get('blog/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9-]{1,120}');
        $r->get('_preview/post/{post}', [BlogController::class, 'preview'])->where('post', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}');
        $r->get('agents', [AgentSiteController::class, 'directory']);
        $r->get('agents/{slug}/{path?}', [AgentSiteController::class, 'show'])->where(['slug' => '[a-z0-9-]{1,80}', 'path' => '[a-z0-9/\-]{0,130}']);
        $r->get('market', [IdxController::class, 'markets']);
        $r->get('market/{place}', [IdxController::class, 'market'])->where('place', '[a-z0-9-]{2,80}');
        app(SiteExtensions::class)->apply($r); // other modules' pages, e.g. /careers
        $r->get('{path?}', [SiteController::class, 'page'])->where('path', '[A-Za-z0-9/_\-]*');
    }

    public function dispatch(Request $request): Response
    {
        try {
            $response = $this->router->dispatch($request);
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            $response = response()->view('sites::missing', ['site' => null], 404)->header('Cache-Control', 'public, max-age=60');
        }
        // A moved address: send it on to its new page (only when nothing else answered it).
        if ($response->getStatusCode() === 404 && $request->isMethod('GET')) {
            $site = Site::query()->where('kind', 'brokerage')->first();
            $path = '/'.ltrim($request->path(), '/').($request->getQueryString() !== null ? '?'.$request->getQueryString() : '');
            $to = $site !== null ? (app(Redirects::class)->find($site, $path) ?? app(Redirects::class)->find($site, '/'.ltrim($request->path(), '/'))) : null;
            if ($to !== null) {
                return redirect(str_starts_with($to['to'], '/') ? $request->getSchemeAndHttpHost().$to['to'] : $to['to'], $to['code'])->header('Cache-Control', 'public, max-age=3600');
            }
        }

        return $response;
    }
}
