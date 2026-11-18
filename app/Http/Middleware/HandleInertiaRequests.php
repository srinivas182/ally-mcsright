<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Fortify\Features;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\PaymentSettings;
use Modules\Billing\Usage\UsageMeter;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Impersonation;
use Modules\Core\Identity\Security\TwoFactorPolicy;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Notices\Notices;
use Modules\Core\Simple\EasierReading;
use Modules\Core\Simple\SimpleMode;
use Modules\Core\Tenancy\TenantContext;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    /**
     * @return array{name: string, isHq: bool}|null
     */
    private function tenant(): ?array
    {
        $context = app(TenantContext::class);

        return $context->check() ? ['name' => $context->current()->name, 'isHq' => $context->current()->isHq()] : null;
    }

    /**
     * @return array{secondFactorMissing: bool, secondFactorDaysLeft: int|null, passkeyPrompt: bool}|null
     */
    private function security(Request $request): ?array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        $policy = app(TwoFactorPolicy::class);
        $hasPasskey = $user->passkeys()->exists();
        $dismissed = $user->passkey_prompt_dismissed_at;

        return [
            'secondFactorMissing' => $policy->mustSetUpNow($user),
            'secondFactorDaysLeft' => $policy->daysLeft($user), // SX3: the reminder during the grace period
            'passkeyPrompt' => Features::canManagePasskeys() && ! $hasPasskey && ($dismissed === null || $dismissed->lt(now()->subDays(30))),
        ];
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // The brokerage (or HQ) this page belongs to, and the menu this user may see.
            'tenant' => fn (): ?array => $this->tenant(),
            'security' => fn (): ?array => $this->security($request),
            'paymentsTestMode' => fn (): bool => app(PaymentSettings::class)->summary()['mode'] === 'test'
                && app(PaymentSettings::class)->summary()['test_set'],
            'impersonation' => function () use ($request): ?array {
                $data = app(Impersonation::class)->current($request);

                return $data === null ? null : ['by' => $data['by_name'], 'target' => $data['target_name'], 'expires_at' => $data['expires_at']];
            },
            'notices' => fn (): ?array => $request->user() instanceof User
                ? ['unread' => app(Notices::class)->unread($request->user())] : null,
            'simple' => fn (): ?array => $request->user() instanceof User ? ['mode' => SimpleMode::mode($request->user()), 'locale' => SimpleMode::locale($request->user()),
                'words' => SimpleMode::words(SimpleMode::locale($request->user())), 'locales' => SimpleMode::LOCALES] : null,
            'reading' => fn (): array => EasierReading::for($request->user() instanceof User ? $request->user() : null), // AX1
            'navigation' => fn (): array => app(NavigationRegistry::class)->for($request->user() instanceof User ? $request->user() : null),
            // The credit meter at the foot of the menu (brokerage workspaces only).
            'credits' => function () use ($request): ?array {
                $user = $request->user();
                $context = app(TenantContext::class);
                if (! $user instanceof User || $user->kind === UserKind::HqStaff || ! $context->check() || $context->current()->isHq()) {
                    return null;
                }

                $meter = app(CreditLedger::class)->meter($user);

                // Owners and staff also see this month's included texts, call minutes and map views.
                return $meter['scope'] === 'brokerage'
                    ? $meter + ['usage' => app(UsageMeter::class)->summary(), 'maps_paused' => app(UsageMeter::class)->mapsPaused()]
                    : $meter;
            },
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
