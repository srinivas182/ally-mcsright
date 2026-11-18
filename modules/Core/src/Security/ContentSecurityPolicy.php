<?php

declare(strict_types=1);

namespace Modules\Core\Security;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Modules\Core\Support\Connections;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SX2: the content security policy — which scripts, styles, frames and connections a page may use — so a script
 * slipped into a page (cross-site scripting) cannot run or send data away.
 *
 * - Scripts: RightAlly's own files, inline scripts that carry this response's nonce (Blade: nonce="{{ Vite::cspNonce() }}"),
 *   and the few outside scripts RightAlly loads (bot checks, bank linking, maps, card payments). No eval.
 * - Styles allow inline (React and the website themes set styles inline); fonts from Google Fonts.
 * - Images, video embeds and connections may use any https address (listing photos, tours, map tiles, outside
 *   services); plugins never; forms post to https only; framing by the same site only.
 * - Mode (config core.csp): "report" (report-only; the default until reports are reviewed), "enforce" or "off".
 *   A page that sets its own policy (Movewise, postcards, health checks) keeps it.
 *
 * Browsers send what the policy blocked (or would block) to /csp-report, answered here before any routing (website
 * hosts included). Reports are counted per surface, rule and blocked address (the address's host only, and the
 * page's path without its query, so no personal details are kept), at most 60 a minute per visitor and 2,000 rows.
 */
final class ContentSecurityPolicy
{
    public const REPORT_PATH = 'csp-report';

    /** Outside scripts RightAlly itself loads. */
    public const SCRIPT_HOSTS = ['https://challenges.cloudflare.com', 'https://js.hcaptcha.com', 'https://www.google.com', 'https://www.gstatic.com',
        'https://cdn.plaid.com', 'https://cdn.teller.io', 'https://api.mapbox.com', 'https://js.stripe.com'];

    private const MAX_ROWS = 2000;

    public static function mode(): string
    {
        $mode = (string) config('core.csp', 'report');

        return in_array($mode, ['report', 'enforce', 'off'], true) ? $mode : 'report';
    }

    /** Make the nonce for this response (Vite tags and inline scripts use it). */
    public static function prepare(): void
    {
        if (self::mode() !== 'off') {
            Vite::useCspNonce();
        }
    }

    /** Add the policy header unless the page set its own. */
    public static function apply(Request $request, Response $response): void
    {
        $mode = self::mode();
        $h = $response->headers;
        if ($mode === 'off' || $h->has('Content-Security-Policy') || $h->has('Content-Security-Policy-Report-Only')) {
            return;
        }
        $h->set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', self::policy((string) Vite::cspNonce(), self::surface($request)));
    }

    public static function policy(string $nonce, string $surface): string
    {
        $dev = Vite::isRunningHot() ? ' '.rtrim((string) preg_replace('#^(https?://[^/]+).*$#', '$1', trim((string) @file_get_contents(public_path('hot')))), '/') : '';
        $wsDev = $dev !== '' ? ' '.preg_replace('#^http#', 'ws', trim($dev)) : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' ".implode(' ', self::SCRIPT_HOSTS).$dev,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://api.mapbox.com".$dev,
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob: https:",
            "connect-src 'self' https: wss:".$wsDev,
            "frame-src 'self' https:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https:",
            "frame-ancestors 'self'",
            'report-uri /'.self::REPORT_PATH.'?s='.$surface,
        ]);
    }

    /** Which part of RightAlly the page belongs to: back office, HQ or public (websites, sign-in, client links). */
    public static function surface(Request $request): string
    {
        $route = $request->route();
        $middleware = $route instanceof Route ? implode(' ', array_map(fn ($m): string => is_string($m) ? $m : $m::class, $route->gatherMiddleware())) : '';

        return str_contains($middleware, 'EnsureHqConsole') ? 'hq' : (str_contains($middleware, 'EnsureTenantWorkspace') ? 'back-office' : 'public');
    }

    public static function isReport(Request $request): bool
    {
        return $request->isMethod('POST') && $request->path() === self::REPORT_PATH;
    }

    /** Count one browser report. Always answers 204 (or 429): a browser does nothing with the answer. */
    public static function receive(Request $request): Response
    {
        $key = 'csp-report:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return new Response('', 429);
        }
        RateLimiter::hit($key, 60);
        try {
            $body = json_decode((string) $request->getContent(), true);
            $reports = is_array($body) && isset($body['csp-report']) ? [$body['csp-report']]
                : (is_array($body) && array_is_list($body) ? array_map(fn ($r) => is_array($r) ? ($r['body'] ?? []) : [], $body) : []);
            $surface = in_array($request->query('s'), ['hq', 'back-office', 'public'], true) ? (string) $request->query('s') : 'public';
            foreach (array_slice($reports, 0, 10) as $r) {
                if (is_array($r)) {
                    self::record($surface, $r);
                }
            }
        } catch (Throwable) {
            // A malformed report is ignored.
        }

        return new Response('', 204);
    }

    /**
     * @param  array<string, mixed>  $r  one report body (either format: "violated-directive"/"blocked-uri" or the newer camelCase)
     */
    private static function record(string $surface, array $r): void
    {
        $directive = (string) ($r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? $r['violatedDirective'] ?? '');
        $directive = mb_substr((string) preg_replace('/[^a-z\-]/', '', strtolower(strtok($directive, ' ') ?: '')), 0, 60);
        if ($directive === '') {
            return;
        }
        $blocked = self::blocked((string) ($r['blocked-uri'] ?? $r['blockedURL'] ?? ''));
        $page = mb_substr((string) (parse_url((string) ($r['document-uri'] ?? $r['documentURL'] ?? ''), PHP_URL_PATH) ?: '/'), 0, 200);
        $db = DB::connection(Connections::control())->table('csp_reports');
        $match = ['surface' => $surface, 'directive' => $directive, 'blocked' => $blocked, 'page' => $page];
        if ($db->clone()->where($match)->increment('count', 1, ['last_seen_at' => now()]) === 0 && $db->clone()->count() < self::MAX_ROWS) {
            $db->clone()->insertOrIgnore($match + ['count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        }
    }

    /** The blocked address reduced to its origin ("https://evil.test"), or the keyword the browser sent ("inline", "eval"). */
    public static function blocked(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '' || preg_match('/^[a-z\-]+$/i', $uri) === 1) {
            return mb_substr(strtolower($uri) ?: 'inline', 0, 40);
        }
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme'])) {
            return 'other';
        }
        if (! isset($parts['host'])) {
            return strtolower($parts['scheme']); // data:, blob:
        }

        return mb_substr(strtolower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')), 0, 200);
    }
}
