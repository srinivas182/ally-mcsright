<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/** Where a problem happened (OB1): the route's pattern, never the actual address (which may hold personal data). */
final class Where
{
    public static function surface(Request $request): string
    {
        $host = $request->getHost();

        return match (true) {
            $request->is('api/*') => 'api',
            $host === config('core.hq_host') => $request->is('hq', 'hq/*') ? 'hq' : 'workspace',
            str_contains($host, 'movewise') => 'movewise',
            default => $request->route() !== null ? 'workspace' : 'website',
        };
    }

    public static function location(?Request $request): ?string
    {
        $route = $request?->route();
        if ($request === null || ! $route instanceof Route) {
            return null;
        }

        return mb_substr($request->method().' '.$route->uri().($route->getName() !== null ? ' ('.$route->getName().')' : ''), 0, 250);
    }
}
