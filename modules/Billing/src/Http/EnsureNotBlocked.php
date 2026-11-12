<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Modules\Billing\Entitlements;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a brokerage has chosen "blocked" for agents without a plan, those agents can only reach the
 * plans page, their profile and security settings, and sign out.
 */
final class EnsureNotBlocked
{
    private const ALLOWED = ['plans', 'logout', 'settings/profile', 'settings/security', 'settings/password', 'user/', 'two-factor', 'impersonate/stop'];

    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->entitlements->blocked($user)) {
            return $next($request);
        }
        foreach (self::ALLOWED as $prefix) {
            if ($request->path() === rtrim($prefix, '/') || str_starts_with($request->path(), $prefix)) {
                return $next($request);
            }
        }

        return redirect('/plans');
    }
}
