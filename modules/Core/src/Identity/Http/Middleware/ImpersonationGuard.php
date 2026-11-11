<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Identity\Impersonation;
use Symfony\Component\HttpFoundation\Response;

/**
 * While viewing as someone: end the session after 60 minutes and block sensitive actions.
 */
final class ImpersonationGuard
{
    /** Never reachable while viewing as someone (any method). */
    private const BLOCKED = ['settings/password', 'settings/security', 'settings/sessions', 'settings/trusted-devices', 'settings/identities',
        'user/two-factor', 'user/confirmed-two-factor', 'user/confirm-password', 'user/passkeys', 'passkeys', 'auth/', 'security/', 'users', 'invitations'];

    /** Changes (non-GET) blocked while viewing as someone. */
    private const READ_ONLY = ['settings/profile', 'finance/', 'hq/'];

    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->impersonation->current($request);
        if ($session === null || $request->path() === 'impersonate/stop') {
            return $next($request);
        }

        if ($session['expires_at'] < now()->getTimestamp()) {
            return redirect($this->impersonation->stop($request, 'expired'));
        }

        $path = $request->path();
        $blocked = false;
        foreach (self::BLOCKED as $prefix) {
            $blocked = $blocked || $path === rtrim($prefix, '/') || str_starts_with($path, $prefix);
        }
        foreach (self::READ_ONLY as $prefix) {
            $blocked = $blocked || (! $request->isMethodSafe() && str_starts_with($path, $prefix));
        }

        if ($blocked) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'Not available while viewing as someone else.'], 403)
                : redirect()->back(fallback: '/')->withErrors(['impersonation' => 'Not available while viewing as '.$session['target_name'].'.']);
        }

        return $next($request);
    }
}
