<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * EM4: a "throttle:3,10" limit counts tries on that one action. Laravel's own middleware counts every throttled route
 * together for a person (or an address), so a few ordinary saves could use up a small limit elsewhere and show "Too
 * many tries" on a first click. Each route now keeps its own count; routes that give their own name keep it as before.
 */
final class ThrottleRequestsPerRoute extends ThrottleRequests
{
    /**
     * @param  Request  $request
     */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $where = $route !== null ? ($route->getName() ?? $request->method().' '.$route->uri()) : $request->path();

        return sha1(parent::resolveRequestSignature($request).'|'.$where);
    }
}
