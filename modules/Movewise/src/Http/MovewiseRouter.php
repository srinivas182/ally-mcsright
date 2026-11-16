<?php

declare(strict_types=1);

namespace Modules\Movewise\Http;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Movewise\Http\Controllers\AccountController;
use Modules\Movewise\Http\Controllers\ConnectController;
use Modules\Movewise\Http\Controllers\GrowthController;
use Modules\Movewise\Http\Controllers\JoinController;
use Modules\Movewise\Http\Controllers\SiteController;
use Modules\Movewise\Http\Controllers\ToolsController;
use Modules\Movewise\I18n;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Movewise's public routes (specs/movewise/01–02): kept apart from the back office (no Laravel session, no back-office
 * cookies, cacheable). Every page also lives under /es in Spanish. My Movewise uses its own sign-in cookie, and its
 * changes are checked by origin (see AccountController).
 */
final class MovewiseRouter
{
    /** Movewise pages load nothing from other sites except listing photos. */
    public const CSP = "default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; "
        ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

    private Router $router;

    public function __construct(Dispatcher $events, Container $container)
    {
        $this->router = new Router($events, $container);
        $r = $this->router;
        $r->get('robots.txt', [SiteController::class, 'robots']);
        $r->get('sitemap.xml', [SiteController::class, 'sitemap']);
        $r->get('sitemaps/{state}.xml', [SiteController::class, 'stateSitemap'])->where('state', '[a-z]{2}|pages');
        $r->get('assets/movewise.js', fn () => response((string) file_get_contents(__DIR__.'/../../resources/assets/movewise.js'), 200,
            ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=86400']));
        $r->match(['GET', 'POST'], 'alerts/off/{search}/{sig}', [AccountController::class, 'stopAlerts'])->where(['search' => '[0-9A-Z]{26}', 'sig' => '[a-f0-9]{32}']);
        $r->match(['GET', 'POST'], 'connect/withdraw/{connection}/{sig}', [ConnectController::class, 'withdraw'])->where(['connection' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'sig' => '[a-f0-9]{32}']);
        foreach (['', 'es'] as $prefix) {
            $r->group(['prefix' => $prefix], function (Router $r): void {
                $r->get('/', [SiteController::class, 'home']);
                $r->get('homes', [SiteController::class, 'search']);
                $r->get('homes/{listing}/{slug?}', [SiteController::class, 'listing'])->where(['listing' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'slug' => '[a-z0-9-]{0,140}']);
                $r->get('agents', [JoinController::class, 'show']);
                $r->post('agents/join', [JoinController::class, 'join']);
                $r->get('afford', [ToolsController::class, 'afford']);
                $r->get('net-sheet', [ToolsController::class, 'netSheet']);
                $r->get('move-planner', [ToolsController::class, 'planner']);
                $r->match(['GET', 'POST'], 'home-value', [ToolsController::class, 'homeValue']);
                $r->post('_auth/start', [AccountController::class, 'start']);
                $r->post('_auth/verify', [AccountController::class, 'verify']);
                $r->get('_me', [AccountController::class, 'me']);
                $r->post('_me/homes', [AccountController::class, 'saveHome']);
                $r->post('_me/searches', [AccountController::class, 'saveSearch']);
                $r->post('_ailly', [AccountController::class, 'ailly']);
                $r->get('connect', [ConnectController::class, 'start']);
                $r->post('connect', [ConnectController::class, 'saveAbout']);
                $r->get('connect/{show}', [ConnectController::class, 'show'])->where('show', '[0-9A-HJKMNP-TV-Z]{26}');
                $r->post('connect/{show}/phone', [ConnectController::class, 'phone'])->where('show', '[0-9A-HJKMNP-TV-Z]{26}');
                $r->post('connect/{show}/choose', [ConnectController::class, 'choose'])->where('show', '[0-9A-HJKMNP-TV-Z]{26}');
                $r->post('account/connections/{connection}/wait', [ConnectController::class, 'wait'])->where('connection', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}');
                $r->post('account/connections/{connection}/feedback', [ConnectController::class, 'feedback'])->where('connection', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}');
                $r->post('account/connections/{connection}/{action}', [ConnectController::class, 'manage'])->where(['connection' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'action' => 'pause|resume|stop|change']);
                $r->get('account', [AccountController::class, 'account']);
                $r->get('account/export', [AccountController::class, 'export']);
                $r->post('account/searches/{search}', [AccountController::class, 'frequency'])->where('search', '[0-9A-Z]{26}');
                $r->post('account/plan', [AccountController::class, 'plan']);
                $r->post('account/delete', [AccountController::class, 'delete']);
                $r->post('account/sign-out', [AccountController::class, 'signOut']);
                $r->get('{page}', [SiteController::class, 'page'])->where('page', 'how-movewise-is-paid|privacy|terms|fair-housing|brokers|accessibility');
                $r->get('agent/{agent}/{slug?}', [GrowthController::class, 'agent'])->where(['agent' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'slug' => '[a-z0-9-]{0,80}']);
                $r->get('{state}', [SiteController::class, 'state'])->where('state', '[a-z-]{4,20}');
                $r->get('{state}/{place}', [GrowthController::class, 'place'])->where(['state' => '[a-z-]{4,20}', 'place' => '\d{5}|[a-z0-9-]{2,60}']);
                $r->get('{state}/{city}/{hood}', [GrowthController::class, 'hood'])->where(['state' => '[a-z-]{4,20}', 'city' => '[a-z0-9-]{2,60}', 'hood' => '[a-z0-9-]{2,80}']);
            });
        }
    }

    public function dispatch(Request $request): Response
    {
        // The language is per request (Octane keeps workers alive), so it is always set here.
        I18n::set($request->path() === 'es' || str_starts_with($request->path(), 'es/') ? 'es' : 'en');
        try {
            $response = $this->router->dispatch($request);
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            $response = response()->view('movewise::missing', ['site' => app(SiteController::class)->chrome()], 404)->header('Cache-Control', 'public, max-age=60');
        }
        $response->headers->set('Content-Security-Policy', self::CSP);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
