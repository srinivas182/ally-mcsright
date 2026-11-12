<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Modules\Billing\Http\EnsureNotBlocked;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Core\Identity\Http\Middleware\EnsureSecondFactor;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;
use Modules\Core\Identity\Http\Middleware\EnsureValidSignedInUser;
use Modules\Core\Identity\Http\Middleware\ImpersonationGuard;
use Modules\Core\Tenancy\Http\Middleware\ResolveTenant;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Tenant resolution runs first on every web request (specs/core/01).
        $middleware->web(prepend: [
            ResolveTenant::class,
        ], append: [
            EnsureValidSignedInUser::class,
            EnsureSecondFactor::class,
            ImpersonationGuard::class,
            EnsureNotBlocked::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias(['tenant' => ResolveTenant::class]);

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
    })->create();
