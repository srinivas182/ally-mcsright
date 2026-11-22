<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Security\ContentSecurityPolicy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 61 (penetration-test preparation): the browser security headers on every response, unless a page set its
 * own (Movewise sets a full content security policy; some downloads set "no-referrer").
 *
 * - No content-type guessing; framing by the same site only (RightAlly never asks to be framed: brokerage pages are
 *   served on the brokerage's own address, specs/websites/05).
 * - The referrer is sent only to the same site in full; other sites get the origin.
 * - Browser features: only the microphone (consented recordings), location ("near me") and payments, on this site.
 * - HSTS for a year on https. Not for sub-domains: a brokerage's own domain may have other sites under it.
 * - SX2: the content security policy (Security\ContentSecurityPolicy), report-only until reviewed, and its reports.
 */
final class SecurityHeaders
{
    public const PERMISSIONS = 'camera=(self), microphone=(self), geolocation=(self), payment=(self), usb=(), serial=(), browsing-topics=()';

    public function handle(Request $request, Closure $next): Response
    {
        if (ContentSecurityPolicy::isReport($request)) { // SX2: browsers' policy reports, on every host
            return ContentSecurityPolicy::receive($request);
        }
        ContentSecurityPolicy::prepare();
        $response = $next($request);
        ContentSecurityPolicy::apply($request, $response);
        $h = $response->headers;
        $embeddable = $h->has('X-Allow-Embed'); // WB1: the website editor's draft preview, framed by its own back office
        $h->remove('X-Allow-Embed');
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS,
        ] as $name => $value) {
            if (! $h->has($name) && ! ($embeddable && $name === 'X-Frame-Options')) {
                $h->set($name, $value);
            }
        }
        if ($request->isSecure() && ! $h->has('Strict-Transport-Security')) {
            $h->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
