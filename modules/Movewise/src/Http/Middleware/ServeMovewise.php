<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Movewise\Http\MovewiseRouter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Movewise's public host is served by its own small router, with no session or cookies, so pages are cacheable at
 * the edge (specs/movewise/01). Its other domains send visitors to it with a permanent redirect that keeps the path,
 * so search engines see one site.
 */
final class ServeMovewise
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $main = strtolower((string) config('movewise.host'));
        if ($main !== '' && $host === $main) {
            return app(MovewiseRouter::class)->dispatch($request);
        }
        if (in_array($host, array_map('strtolower', (array) config('movewise.redirect_hosts', [])), true)) {
            return redirect()->away('https://'.$main.$request->getRequestUri(), 301)->header('Cache-Control', 'public, max-age=86400');
        }

        return $next($request);
    }
}
