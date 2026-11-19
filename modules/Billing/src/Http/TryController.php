<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Onboarding\TryDemo;

/** MK2: "Try it now" — from RightAlly's website straight into a demo with made-up clients, no sign-up. */
final class TryController
{
    public function start(TryDemo $demo): RedirectResponse
    {
        abort_unless(TryDemo::enabled(), 404);

        return redirect()->away($demo->visit());
    }

    /** The one-time link (signed, 10 minutes) on the demo brokerage's address. */
    public function enter(Request $request, string $user, TryDemo $demo): RedirectResponse
    {
        abort_unless(TryDemo::enabled(), 404);
        $guest = $demo->guest($user);
        abort_if($guest === null, 404);
        Auth::guard('web')->login($guest);
        $request->session()->regenerate();

        return redirect('/simple');
    }
}
