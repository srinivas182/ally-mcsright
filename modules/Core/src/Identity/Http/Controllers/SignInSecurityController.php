<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Models\TrustedDevice;
use Modules\Core\Identity\Security\SignInSecurity;

final class SignInSecurityController
{
    public function endSession(Request $request, string $id, SignInSecurity $security): RedirectResponse
    {
        $security->endSession(self::user($request), $id);

        return back();
    }

    public function signOutOthers(Request $request, SignInSecurity $security): RedirectResponse
    {
        $user = self::user($request);
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $security->signOutOthers($user, $request->session()->getId());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Signed out of all other devices.']);

        return back();
    }

    public function revokeTrustedDevice(Request $request, int $id): RedirectResponse
    {
        TrustedDevice::query()->where('user_id', self::user($request)->id)->whereKey($id)->delete();

        return back();
    }

    public function dismissPasskeyPrompt(Request $request): RedirectResponse
    {
        self::user($request)->forceFill(['passkey_prompt_dismissed_at' => CarbonImmutable::now()])->save();

        return back();
    }

    /**
     * From the new-sign-in email: sign out everywhere and send a password reset link.
     */
    public function secureAccount(string $user, SignInSecurity $security): Response
    {
        $account = User::query()->where('ulid', $user)->firstOrFail();
        $security->signOutEverywhere($account);
        Password::broker()->sendResetLink(['email' => $account->email]);

        return Inertia::render('auth/account-secured');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
