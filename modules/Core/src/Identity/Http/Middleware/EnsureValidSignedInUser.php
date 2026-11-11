<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Middleware;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * On every request: a signed-in user must belong to this request's tenant and be active; otherwise
 * they are signed out (e.g. deactivated while logged in, or a session carried to another domain).
 * Also records activity at most every five minutes.
 */
final class EnsureValidSignedInUser
{
    private const ACTIVITY_INTERVAL_MINUTES = 5;

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The central sign-in hand-off host never holds brokerage sessions; leave them alone.
        if ($request->getHost() === (string) config('core.sso_host')) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user instanceof User) {
            $valid = $this->context->check()
                && $user->tenant_id === $this->context->id()
                && $user->isActive();

            if (! $valid) {
                $guard->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                // Central hosts have no sign-in page: carry on as a guest.
                if (! $this->context->check()) {
                    return $next($request);
                }

                return $request->expectsJson()
                    ? response()->json(['message' => 'Your session has ended.'], 401)
                    : redirect('/login')->with('status', 'Your session has ended. Please sign in again.');
            }

            $this->touch($user);
        }

        return $next($request);
    }

    private function touch(User $user): void
    {
        $now = CarbonImmutable::now();

        if ($user->last_active_at === null || $user->last_active_at->lt($now->subMinutes(self::ACTIVITY_INTERVAL_MINUTES))) {
            $user->newQuery()->whereKey($user->id)->update(['last_active_at' => $now]);
            $user->setRawAttributes(array_merge($user->getAttributes(), ['last_active_at' => $now->toDateTimeString()]), true);
        }
    }
}
