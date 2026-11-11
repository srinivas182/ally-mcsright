<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Support\AuthenticatedUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Modules\Core\Identity\Models\TrustedDevice;
use Modules\Core\Identity\Models\UserIdentity;
use Modules\Core\Identity\Security\SignInSecurity;
use Modules\Core\Identity\Security\SingleSignOn;
use Modules\Core\Identity\Security\TwoFactorPolicy;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? AuthenticatedUser::from($request)
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at?->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'sessions' => app(SignInSecurity::class)->sessions(AuthenticatedUser::from($request), $request->session()->getId()),
            'trustedDevices' => TrustedDevice::query()->where('user_id', AuthenticatedUser::from($request)->id)
                ->where('expires_at', '>', now())->orderByDesc('last_used_at')->get()
                ->map(fn (TrustedDevice $d): array => ['id' => $d->id, 'label' => $d->label, 'expires_at' => $d->expires_at->toIso8601String()])->all(),
            'twoFactorRequired' => app(TwoFactorPolicy::class)->requires(AuthenticatedUser::from($request)),
            'ssoProviders' => app(SingleSignOn::class)->configured(),
            'identities' => UserIdentity::query()->where('user_id', AuthenticatedUser::from($request)->id)->get()
                ->map(fn (UserIdentity $i): array => ['provider' => $i->provider, 'email' => $i->email])->values(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = AuthenticatedUser::from($request)->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = AuthenticatedUser::from($request);
        $user->update([
            'password' => $request->password,
        ]);

        // Changing the password signs out every other session and forgets trusted devices.
        app(SignInSecurity::class)->signOutOthers($user, $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
