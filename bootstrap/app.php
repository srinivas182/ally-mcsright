<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Billing\Http\BrokerageReadOnly;
use Modules\Billing\Http\EnsureNotBlocked;
use Modules\Core\Http\Middleware\ConfirmPasswordInline;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Core\Http\Middleware\SecurityHeaders;
use Modules\Core\Http\Middleware\ThrottlePerRoute;
use Modules\Core\Http\Middleware\TrustLoadBalancer;
use Modules\Core\Identity\Http\Middleware\EnsureSecondFactor;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;
use Modules\Core\Identity\Http\Middleware\EnsureValidSignedInUser;
use Modules\Core\Identity\Http\Middleware\ImpersonationGuard;
use Modules\Core\Safeguards\Http\BrokerageMoving;
use Modules\Core\Switches\Http\EnsureAreaOn;
use Modules\Core\Tenancy\Http\Middleware\ResolveTenant;
use Modules\Core\Watchtower\WatchtowerServiceProvider;
use Modules\Movewise\Http\Middleware\ServeMovewise;
use Modules\Sites\Http\Middleware\ServeWebsite;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sprint 63 (launch readiness): trust the load balancer (TrustLoadBalancer: private networks by default).
        $middleware->replace(TrustProxies::class, TrustLoadBalancer::class);

        $middleware->validateCsrfTokens(except: ['billing/webhook', 'billing/twilio/*', 'payout-rails/*']);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Website hosts are served by the site engine before any back-office routing (specs/websites/05). Appended to
        // the global stack, so proxy trust, size limits and input trimming still apply to website requests.
        // Sprint 61: browser security headers on every response, websites included (so it wraps the two below).
        $middleware->append(SecurityHeaders::class);
        $middleware->append(ServeMovewise::class); // Movewise Homes, HQ's consumer site (specs/movewise/01)
        $middleware->append(ServeWebsite::class);

        // Tenant resolution runs first on every web request (specs/core/01).
        $middleware->web(prepend: [
            ResolveTenant::class,
        ], append: [
            EnsureValidSignedInUser::class,
            EnsureSecondFactor::class,
            ImpersonationGuard::class,
            EnsureNotBlocked::class,
            EnsureAreaOn::class, // FL1: switched-off areas and seats (specs/core/16)
            BrokerageMoving::class,
            BrokerageReadOnly::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // UX1: 'password.confirm' opens a password check over the page instead of losing what was typed.
        $middleware->alias(['tenant' => ResolveTenant::class, 'password.confirm' => ConfirmPasswordInline::class, 'throttle' => ThrottlePerRoute::class]); // EM4: each route counts its own tries

        // "Does this page exist on this host?" runs before "are you signed in?", so HQ and
        // back-office pages return a neutral 404 on other hosts instead of a login redirect.
        foreach ([EnsureHqConsole::class, EnsureTenantWorkspace::class, EnsureTenantHost::class] as $hostCheck) {
            $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: $hostCheck);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // OB1: every reported error also goes to Watchtower (specs/core/20); the normal log carries on.
        $exceptions->report(fn (Throwable $e) => WatchtowerServiceProvider::report($e));
        // EM4: in the app, an error never strands someone in a pop-up. A button or form that fails returns them to the
        // page they were on with a plain message; a page that fails to open opens the full error page (whose links work).
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if ($request->header('X-Inertia') === null || $status < 400 || $status === 409 || $status === 422) {
                return $response;
            }
            if ($request->isMethod('GET')) {
                return Inertia::location($request->fullUrl());
            }
            Inertia::flash('toast', ['type' => 'error', 'message' => match (true) {
                $status === 429 => 'Too many tries in a short time. Please wait a minute, then try again.',
                $status === 419 => 'This page was open for a long time. Please try again.',
                $status === 403 => 'You do not have access to do that. Ask your broker if you need it.',
                $status === 404 => 'We could not find that. It may have been removed. Refresh the page and try again.',
                default => 'Something went wrong on our side, and we have been told. Nothing you saved is lost; please try again in a few minutes.',
            }]);

            return back(303);
        });
    })->create();
