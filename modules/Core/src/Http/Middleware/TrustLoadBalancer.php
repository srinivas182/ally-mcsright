<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Sprint 63 (launch readiness): behind the load balancer every request arrives from the balancer's address. Trusting
 * it gives each visitor their own address for rate limits and lets the app see https (secure cookies, HSTS, https
 * links). Private network ranges by default; core.trusted_proxies (TRUSTED_PROXIES) for others, or "*". Read at
 * request time, so it also works with cached config.
 */
final class TrustLoadBalancer extends TrustProxies
{
    public const PRIVATE_NETWORKS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1', '::1'];

    /** @return array<int, string>|string */
    protected function proxies()
    {
        $set = trim((string) config('core.trusted_proxies', ''));

        return match (true) {
            $set === '*' => '*',
            $set !== '' => array_values(array_filter(array_map('trim', explode(',', $set)))),
            default => self::PRIVATE_NETWORKS,
        };
    }
}
