<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Modules\Core\Identity\Security\TwoFactorPolicy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users who must use a second factor and have none are sent to set one up before anything else.
 */
final class EnsureSecondFactor
{
    /** Paths that stay reachable while setting up a second factor. */
    private const ALLOWED = ['settings/security', 'user/two-factor', 'user/confirmed-two-factor', 'user/confirm-password',
        'user/passkeys', 'passkeys', 'logout', 'two-factor-challenge', '.well-known', 'security/'];

    public function __construct(private readonly TwoFactorPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->requires($user) || $this->policy->satisfied($user)) {
            return $next($request);
        }

        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                return $next($request);
            }
        }

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => 'Set up two-step verification to continue.'], 403)
            : redirect('/settings/security')->with('status', 'two-factor-required');
    }
}
