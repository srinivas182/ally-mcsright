<?php

declare(strict_types=1);

namespace Modules\Movewise\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Movewise pages are cached for everyone, so they carry no CSRF token. Instead every request that changes something
 * must come from Movewise's own pages (Origin or Referer on Movewise's host) and not too fast (specs/movewise/02).
 */
final class Guard
{
    public static function check(Request $request, string $key, int $perMinute): void
    {
        $origin = (string) ($request->headers->get('Origin') ?? $request->headers->get('Referer') ?? '');
        abort_unless(parse_url($origin, PHP_URL_HOST) === config('movewise.host'), 403);
        abort_if(RateLimiter::tooManyAttempts('movewise:'.$key, $perMinute), 429);
        RateLimiter::hit('movewise:'.$key, 60);
    }
}
