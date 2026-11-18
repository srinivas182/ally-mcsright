<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Modules\Core\Identity\Security\SignInHelp;

/** SX3: "I lost my phone" on the two-step page, and the broker's answer on People. */
final class SignInHelpController
{
    /** From the two-step page: the password was already checked (Fortify keeps the person in "login.id"). */
    public function ask(Request $request, SignInHelp $help): RedirectResponse
    {
        $user = User::query()->find((int) $request->session()->get('login.id'));
        if (! $user instanceof User) {
            return redirect('/login');
        }

        return back()->with('status', $help->ask($user) ? 'sign-in-help-asked' : 'sign-in-help-support');
    }

    public function reset(Request $request, string $help, SignInHelp $requests): RedirectResponse
    {
        Gate::authorize('core.users.manage');
        $request->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Tick the box to say you have checked it is really them.']);
        $user = $requests->reset($help, self::actor($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name}'s sign-in was reset. They have been emailed a link to choose a new password."]);

        return back();
    }

    public function decline(Request $request, string $help, SignInHelp $requests): RedirectResponse
    {
        Gate::authorize('core.users.manage');
        $user = $requests->decline($help, self::actor($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Nothing changed for {$user->name}."]);

        return back();
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
