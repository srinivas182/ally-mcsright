<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as Router;
use Modules\Core\Http\Middleware\SecurityHeaders;
use Modules\Sites\SiteSetup;

/*
| Sprint 58 (integration and regression; pen-test preparation): every one of the ~1,300 routes is accounted for.
|
| - Every HQ route is behind the HQ console guard.
| - Every signed-in route belongs to a brokerage's or HQ's own address.
| - A route anyone can open must match the reviewed public list below, with the reason it is public. A new public
|   route fails this test until someone reviews it and adds it here.
| - A public route that changes something is rate-limited (or has its own limiter, listed below).
*/

/** Reviewed public routes, by reason. Patterns match the route's address without the leading slash. */
const RS_PUBLIC = [
    'brokerage website pages, SEO and app files' => ['#^$#', '#^\{slug\}$#', '#^es(/|$)#', '#^blog(/|$)#', '#^sitemap\.xml$#', '#^robots\.txt$#', '#^llms\.txt$#', '#^indexnow-key\.txt$#',
        '#^manifest\.webmanifest$#', '#^studio-fonts/#', '#^preview/\{ulid\}$#', '#^up$#', '#^app$#'],
    'signing in (the sign-in itself is the check)' => ['#^/$#', '#^login$#', '#^login/go$#', '#^sign-in$#', '#^forgot-password$#', '#^reset-password(/|$)#', '#^two-factor-challenge(/help)?$#',
        '#^passkeys/login#', '#^invitations/\{token\}$#', '#^auth/#', '#^oauth/#', '#^\.well-known/#', '#^authorize(/|$)#', '#^register$#', '#^token$#',
        '#^impersonate/accept$#', '#^security/secure-account/\{user\}$#'],
    'signing up, partner and referral links' => ['#^start(/|$)#', '#^agents(/|$)#', '#^partners/#', '#^r/\{code\}$#', '#^affiliate/#', '#^health-check(/|$)#', '#^try$#', '#^try/enter/\{user\}$#'],
    'links sent to clients by email or text (token or signature checked in the controller)' => ['#^my-deal(/|$)#', '#^sign/#', '#^verify-document$#', '#^offer/#',
        '#^cda/#', '#^appointment/#', '#^consent/#', '#^deal-upload/#', '#^take-my-people/#', '#^book/#', '#^l/#', '#^cma/#', '#^estimate/#', '#^calendar/#',
        '#^shared/doc/#', '#^privacy-copy/#', '#^my-home/#', '#^showing-feedback/#', '#^alerts/\{search\}/stop$#', '#^home-reports/\{report\}/stop$#',
        '#^market-reports/\{report\}/stop$#', '#^recruiting-stop/#', '#^morning/\{user\}$#'],
    'webhooks (each checks the provider\'s signature or secret)' => ['#^webhooks/#', '#^billing/(webhook|twilio/call-status)$#', '#^payout-rails/#',
        '#^connect/apps/\{app\}/webhook$#', '#^connect/hooks/#', '#^connect/oauth/callback$#', '#^connect/canva/return$#'],
    'Watchtower (OB1): the scheduler pulse for the uptime checker, and masked browser crash reports, rate-limited' => ['#^up/scheduler$#', '#^watchtower/browser-error$#'],
    'API with its own keys and limits' => ['#^api/v1/#', '#^api/zapier/#', '#^api/leads$#', '#^mcp$#'],
    'framework: signed file links, developer tools (local only)' => ['#^storage/\{path\}$#', '#^_inertia/devtools/#', '#^dev/design$#'],
];

/** Public routes that change something without a route rate limit, and why that is fine. */
const RS_UNTHROTTLED = [
    '#^api/v1/#' => 'ApiAuth limits every key per minute',
    '#^mcp$#' => 'closing an MCP session; the MCP POST is limited',
    '#^my-deal/sign-out$#' => 'only signs the visitor out',
    '#^storage/\{path\}$#' => 'signed temporary upload links only',
];

/**
 * The route's middleware with groups and aliases resolved to classes (what actually runs).
 *
 * @return list<string>
 */
function rsMiddleware(Route $route): array
{
    return array_values(array_map(fn ($m): string => is_string($m) ? $m : $m::class, app('router')->gatherRouteMiddleware($route)));
}

function rsSignedIn(Route $route): bool
{
    foreach (rsMiddleware($route) as $m) {
        $m = (string) $m;
        if ($m === 'auth' || str_starts_with($m, 'auth:') || str_contains($m, 'Middleware\\Authenticate') || str_ends_with($m, '\\ApiAuth') || str_ends_with($m, '\\CentralMcpToken')) {
            return true;
        }
    }

    return false;
}

function rsHas(Route $route, string $needle): bool
{
    return collect(rsMiddleware($route))->contains(fn (string $m): bool => str_contains($m, $needle));
}

/** @return list<Route> */
function rsRoutes(): array
{
    return array_values(Router::getRoutes()->getRoutes());
}

it('guards every HQ route with the HQ console check', function (): void {
    $open = collect(rsRoutes())->filter(fn (Route $r): bool => preg_match('#^hq(/|$)#', $r->uri()) === 1 && ! rsHas($r, 'EnsureHqConsole'))
        ->map(fn (Route $r): string => implode('|', $r->methods()).' '.$r->uri())->values()->all();

    expect($open)->toBe([]);
});

it('ties every signed-in route to a brokerage or to HQ', function (): void {
    // EnsureTenantWorkspace (back office), EnsureHqConsole (HQ) or EnsureTenantHost (sign-in pages on any tenant's
    // own address, HQ included): all three run before "are you signed in?" (bootstrap/app.php).
    $loose = collect(rsRoutes())->filter(fn (Route $r): bool => rsSignedIn($r) && ! rsHas($r, 'EnsureTenantWorkspace') && ! rsHas($r, 'EnsureHqConsole') && ! rsHas($r, 'EnsureTenantHost')
        // The central MCP address picks the brokerage from the token (CentralMcpToken); Passport's own consent and
        // refresh routes serve the brokerage whose app the person is signing in to.
        && $r->uri() !== 'mcp' && preg_match('#^oauth/(authorize|device/authorize|token/refresh)$#', $r->uri()) !== 1)
        ->map(fn (Route $r): string => implode('|', $r->methods()).' '.$r->uri())->values()->all();

    expect($loose)->toBe([]);
});

it('only leaves reviewed routes open to anyone', function (): void {
    $patterns = array_merge(...array_values(RS_PUBLIC));
    $unreviewed = collect(rsRoutes())->reject(fn (Route $r): bool => rsSignedIn($r))
        ->reject(fn (Route $r): bool => collect($patterns)->contains(fn (string $p): bool => preg_match($p, $r->uri()) === 1))
        ->map(fn (Route $r): string => implode('|', $r->methods()).' '.$r->uri())->values()->all();

    expect($unreviewed)->toBe([]);
});

it('rate-limits every public route that changes something', function (): void {
    $open = collect(rsRoutes())->reject(fn (Route $r): bool => rsSignedIn($r))
        ->filter(fn (Route $r): bool => array_diff($r->methods(), ['GET', 'HEAD']) !== [])
        ->reject(fn (Route $r): bool => rsHas($r, 'ThrottleRequests'))
        ->reject(fn (Route $r): bool => collect(array_keys(RS_UNTHROTTLED))->contains(fn (string $p): bool => preg_match($p, $r->uri()) === 1))
        ->map(fn (Route $r): string => implode('|', $r->methods()).' '.$r->uri())->values()->all();

    expect($open)->toBe([]);
});

it('refuses a sixth password-reset request in a minute, and the account pages exist only on a tenant address', function (): void {
    Notification::fake();
    $this->onTenantHost('acme');
    foreach (range(1, 5) as $n) {
        $this->post('/forgot-password', ['email' => "person{$n}@example.test"])->assertStatus(302);
    }
    $this->post('/forgot-password', ['email' => 'person6@example.test'])->assertStatus(429);
    $this->post('/reset-password', ['token' => 'x', 'email' => 'a@example.test', 'password' => 'x', 'password_confirmation' => 'x'])->assertStatus(429);

    $this->get('http://rightally.site/settings/profile')->assertNotFound();
});

it('sends the browser security headers on the back office, websites and the API, and HSTS on https only', function (): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['area' => 'Austin'], $owner), true, $owner);

    foreach (['/login', 'http://acme.rightally.site/', '/api/v1/openapi.json'] as $url) {
        $this->get($url)->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Permissions-Policy', SecurityHeaders::PERMISSIONS)
            ->assertHeaderMissing('Strict-Transport-Security');
    }
    $this->get('https://acme.rightally.app/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
