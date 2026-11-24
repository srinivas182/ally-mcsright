<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

/**
 * UX1: asking for the password again no longer throws away what someone typed. When a form in the app (an Inertia
 * request that changes something) needs a fresh password, the person stays on the page: a small "Confirm your password"
 * box opens over it and, once confirmed, the same form is sent again with everything they typed. Links and downloads
 * (GET) still go to the full "Confirm your password" page, as before.
 */
final class ConfirmPasswordInline extends RequirePassword
{
    /** The error key the page's password box listens for. */
    public const KEY = 'confirm_password';

    /**
     * @param  Request  $request
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if ($request->header('X-Inertia') !== null && ! $request->isMethod('GET') && $this->shouldConfirmPassword($request, $passwordTimeoutSeconds === null ? null : (int) $passwordTimeoutSeconds)) {
            return back()->withErrors([self::KEY => 'Please confirm your password to continue.']);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
