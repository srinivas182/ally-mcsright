<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Core\Watchtower\SlowPages;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Times back-office requests (OB1): a small sample of every route, plus every request over 2 seconds. Written after
 * the response has gone, so no one waits for it.
 */
final class TimeRequests
{
    public const STARTED = 'watchtower.started';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED, hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get(self::STARTED);
        $route = $request->route();
        if (! is_int($started) || ! $route instanceof Route) {
            return;
        }
        try {
            SlowPages::record($request->method().' '.$route->uri(), (int) round((hrtime(true) - $started) / 1e6));
        } catch (Throwable) {
            // timing must never break a request
        }
    }
}
