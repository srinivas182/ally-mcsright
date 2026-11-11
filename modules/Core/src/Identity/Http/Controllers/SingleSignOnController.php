<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Modules\Core\Identity\Security\SingleSignOn;
use Modules\Core\Identity\Security\TrustedDevices;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SingleSignOnController
{
    public function __construct(private readonly SingleSignOn $sso) {}

    /** Brokerage host: start sign-in (or linking, when signed in and ?intent=link). */
    public function start(Request $request, string $provider): RedirectResponse
    {
        $intent = $request->query('intent') === 'link' ? 'link' : 'login';
        $user = $request->user();
        if ($intent === 'link' && ! $user instanceof User) {
            return redirect('/login');
        }

        try {
            return redirect()->away($this->sso->start($provider, $intent, $request, $user instanceof User ? $user : null));
        } catch (ValidationException $e) {
            return redirect('/login')->withErrors($e->errors());
        }
    }

    /** Central host: on to Google/Microsoft. */
    public function toProvider(Request $request, string $provider): Response
    {
        try {
            return $this->sso->toProvider($provider, (string) $request->query('state', ''));
        } catch (ValidationException) {
            abort(400, 'This sign-in link is not valid. Please start again from your brokerage sign-in page.');
        }
    }

    /** Central host: back from Google/Microsoft, hand a ticket to the brokerage host. */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        try {
            return redirect()->away($this->sso->callback($provider, (string) $request->query('state', '')));
        } catch (Throwable) {
            abort(400, 'Sign-in did not finish. Please start again from your brokerage sign-in page.');
        }
    }

    /** Brokerage host: redeem the ticket. */
    public function complete(Request $request, TrustedDevices $trusted): Response
    {
        try {
            $user = $this->sso->complete((string) $request->query('ticket', ''), $request);
        } catch (ValidationException $e) {
            return redirect($request->user() instanceof User ? '/settings/security' : '/login')->withErrors($e->errors());
        }

        if ($request->user() instanceof User) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Account connected. You can now sign in with it.']);

            return redirect('/settings/security');
        }

        // Two-step verification still applies, unless this device is trusted.
        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null && ! $trusted->isTrusted($user, $request)) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect('/two-factor-challenge');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return app(LoginResponse::class)->toResponse($request);
    }

    public function unlink(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->sso->unlink($user, $provider);

        return back();
    }
}
