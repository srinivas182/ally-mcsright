<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Modules\Core\Identity\Auth\LoginResponse;
use Modules\Core\Identity\Security\SingleSignOn;
use Modules\Core\Identity\Security\SkipTwoFactorOnTrustedDevice;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponse::class, LoginResponse::class);
        // Skip the 2FA prompt on devices the user chose to trust for 30 days.
        $this->app->scoped(RedirectsIfTwoFactorAuthenticatable::class, fn ($app) => $app->make(SkipTwoFactorOnTrustedDevice::class));
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Only active users of this tenant (the user provider is tenant-scoped). Failures are
        // neutral: the response never reveals whether the email exists or why it failed.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', Str::lower(trim((string) $request->input(Fortify::username()))))->first();

            if ($user === null || ! $user->isActive() || $user->password === null
                || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'ssoProviders' => app(SingleSignOn::class)->configured(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        // SX3: the "I lost my phone" answer comes back as the session status.
        Fortify::twoFactorChallengeView(fn (Request $request) => Inertia::render('auth/two-factor-challenge', ['status' => $request->session()->get('status')]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            // Per tenant (host), email and IP: the same email in two brokerages is two accounts.
            $throttleKey = Str::transliterate($request->getHost().'|'.Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Sprint 58 (pen-test prep): "forgot password" and "reset password" are public; cap them per address and
        // host so nobody can flood a person with reset emails or guess reset tokens. The password broker also
        // allows one email per minute per account (config/auth.php).
        RateLimiter::for('password-reset', function (Request $request) {
            return [Limit::perMinute(5)->by('pw|'.$request->getHost().'|'.$request->ip()),
                Limit::perHour(10)->by('pw-email|'.$request->getHost().'|'.Str::lower((string) $request->input('email')))];
        });
        $this->app->booted(function (): void {
            // By name, walking the routes: the name index is not refreshed yet at this point.
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (in_array($route->getName(), ['password.email', 'password.update'], true)) {
                    $route->middleware('throttle:password-reset');
                }
            }
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
