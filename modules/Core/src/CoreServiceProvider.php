<?php

declare(strict_types=1);

namespace Modules\Core;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Access\Permissions;
use Modules\Core\Access\Roles;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Branding\BrandColourSource;
use Modules\Core\Branding\NullBrandColourSource;
use Modules\Core\Console\BootstrapHq;
use Modules\Core\Console\CompileDesignTokens;
use Modules\Core\Console\RunTenantLifecycle;
use Modules\Core\Identity\Auth\TenantPasswordBrokerManager;
use Modules\Core\Identity\Auth\TenantUserProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Events\ImpersonationStarted;
use Modules\Core\Identity\Events\ImpersonationStopped;
use Modules\Core\Identity\Events\RepeatedLoginFailures;
use Modules\Core\Identity\Impersonation;
use Modules\Core\Identity\Security\SignInSecurity;
use Modules\Core\Identity\Security\TrustedDevices;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantCache;
use Modules\Core\Tenancy\TenantContext;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

/**
 * Platform Core module.
 *
 * Every RightAlly module is a self-contained package under modules/<Name>/
 * with a module.json manifest and a service provider registered in
 * bootstrap/providers.php. Modules talk to each other only through public
 * interfaces and domain events, never through each other's tables.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Texts and calls use the Twilio account HQ set up (falling back to the server's settings); scoped so a
        // change in HQ settings applies to the next request or job.
        $this->app->singleton(Messaging\MessagingSettings::class);
        $this->app->bindIf(Messaging\SmsSender::class, function ($app): Messaging\SmsSender {
            $settings = $app->make(Messaging\MessagingSettings::class);
            $creds = $settings->credentials();

            return $creds !== null ? new Messaging\TwilioSmsSender($app->make(Factory::class), $creds['sid'], $creds['token'], $settings->smsFrom()) : new Messaging\LogSmsSender;
        });
        $this->app->bindIf(Messaging\VoiceCaller::class, function ($app): Messaging\VoiceCaller {
            $settings = $app->make(Messaging\MessagingSettings::class);
            $creds = $settings->credentials();

            return $creds !== null ? new Messaging\TwilioVoiceCaller($app->make(Factory::class), $creds['sid'], $creds['token'], $settings->voiceFrom()) : new Messaging\LogVoiceCaller;
        });
        $this->app->singleton(PermissionRegistry::class);
        $this->app->scoped(Permissions::class);
        $this->app->scoped(Roles::class);
        $this->app->scoped(AuditLogger::class);
        $this->app->singleton(NavigationRegistry::class);
        $this->mergeConfigFrom(__DIR__.'/../config/core.php', 'core');

        // Scoped: reset between requests (Octane) and between queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantCache::class);
        $this->app->singleton(DomainResolver::class);
        $this->app->bind(BrandColourSource::class, NullBrandColourSource::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'core');
        $this->loadRoutesFrom(__DIR__.'/../routes/hq.php');

        $this->loadRoutesFrom(__DIR__.'/../routes/identity.php');
        $this->registerIdentity();
        $this->registerNavigation();

        $this->registerTenantAwareQueues();
        $this->registerMigrationMacros();

        if ($this->app->runningInConsole()) {
            $this->commands([CompileDesignTokens::class, RunTenantLifecycle::class, BootstrapHq::class, Console\FlushAudit::class, Console\VerifyAudit::class, Console\AuditMaintenance::class, Console\ExpireAccess::class]);
        }
    }

    /**
     * $table->tenant() adds the indexed tenant_id column every tenant-owned table needs.
     * No foreign key: tenants live on the control-plane connection, which can be a
     * different database from the cell (architecture v2 section 3).
     */
    private function registerMigrationMacros(): void
    {
        Blueprint::macro('tenant', function (): void {
            /** @var Blueprint $this */
            $this->unsignedBigInteger('tenant_id');
            $this->index('tenant_id');
        });
    }

    /**
     * Jobs carry the tenant they were dispatched under and run as that tenant.
     */
    /**
     * Tenant-scoped sign-in (specs/core/02) and interim access rules until roles and permissions
     * replace them in Sprints 5-6 (specs/core/03): HQ staff run the HQ console; brokerage owners
     * manage their users. Everything else stays denied by default.
     */
    private function registerIdentity(): void
    {
        Auth::provider('tenant-eloquent', function (Application $app, array $config): TenantUserProvider {
            return new TenantUserProvider($app->make(Hasher::class), (string) $config['model'], $app->make(TenantContext::class));
        });

        // extend(), not singleton(): Laravel's password provider is deferred and would replace a
        // plain binding when it loads. The extender applies whenever the manager is resolved.
        $this->app->extend('auth.password', fn (mixed $manager, Application $app): TenantPasswordBrokerManager => new TenantPasswordBrokerManager($app));

        Gate::define('hq.tenants.manage', fn (User $user): bool => $user->kind === UserKind::HqStaff);

        // Roles and permissions (specs/core/03): registered permissions are decided by the engine.
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('People and settings', [
            'core.users.view' => ['See people in the brokerage'],
            'core.users.invite' => ['Invite people'],
            'core.users.manage' => ['Change and deactivate people'],
            'core.roles.manage' => ['Create roles and assign them'],
            'core.organisation.manage' => ['Manage offices and teams'],
            'core.audit.view' => ['See the audit log'],
        ]);
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $registry = $this->app->make(PermissionRegistry::class);
            if (! $registry->has($ability)) {
                return null;
            }
            $record = $arguments[0] ?? null;

            return $this->app->make(Permissions::class)->allows($user, $ability, $record instanceof Model ? $record : null);
        });

        Event::listen(Login::class, function (Login $event): void {
            // "Log in as" is not the person signing in: no last-login change or device alert.
            $request = $this->app->make('request');
            if ($request->hasSession() && ($request->session()->has(Impersonation::SESSION) || $request->session()->has('impersonation_returning'))) {
                return;
            }
            if ($event->user instanceof User) {
                $event->user->newQuery()->whereKey($event->user->id)->update(['last_login_at' => now(), 'last_active_at' => now()]);
                $this->app->make(SignInSecurity::class)->recordSignIn($event->user, $this->app->make('request'));
            }
        });

        // Audit log (specs/core/04): sign-ins, failures, lockouts and "log in as".
        Event::listen(Login::class, function (Login $e): void {
            $request = $this->app->make('request');
            if ($e->user instanceof User && ! ($request->hasSession() && $request->session()->has(Impersonation::SESSION))) {
                $this->app->make(AuditLogger::class)->record('core.auth.signed_in', $e->user, label: $e->user->name);
            }
        });
        Event::listen(Failed::class, function (Failed $e): void {
            $email = is_string($e->credentials['email'] ?? null) ? $e->credentials['email'] : '';
            $this->app->make(AuditLogger::class)->record('core.auth.sign_in_failed', $e->user instanceof User ? $e->user : null,
                metadata: ['email' => AuditLogger::mask($email)], severity: 'notice', label: $e->user instanceof User ? $e->user->name : AuditLogger::mask($email));
        });
        Event::listen(Lockout::class, fn (Lockout $e) => $this->app->make(AuditLogger::class)->record('core.auth.locked_out',
            metadata: ['email' => AuditLogger::mask((string) $e->request->input('email'))], severity: 'warning'));
        Event::listen(ImpersonationStarted::class, function (ImpersonationStarted $e): void {
            $target = User::query()->find($e->targetUserId);
            $this->app->make(AuditLogger::class)->record('core.impersonation.started', $target, metadata: ['by_user_id' => $e->byUserId, 'by_kind' => $e->byKind, 'reason' => $e->reason], severity: 'warning');
        });
        Event::listen(ImpersonationStopped::class, function (ImpersonationStopped $e): void {
            $target = User::query()->find($e->targetUserId);
            $this->app->make(AuditLogger::class)->record('core.impersonation.stopped', $target, metadata: ['by_user_id' => $e->byUserId, 'by_kind' => $e->byKind, 'why' => $e->why], severity: 'notice');
        });

        // Microsoft sign-in comes from the SocialiteProviders package.
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        // "Trust this device for 30 days" ticked on the 2FA challenge.
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, function (ValidTwoFactorAuthenticationCodeProvided $event): void {
            $request = $this->app->make('request');
            if ($request->boolean('remember_device')) {
                $this->app->make(TrustedDevices::class)->trust($event->user, $request);
            }
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $tenants = $this->app->make(TenantContext::class);
            $email = mb_strtolower((string) ($event->credentials['email'] ?? ''));

            if (! $tenants->check() || $email === '') {
                return;
            }

            $hash = hash('sha256', $email);
            $key = 'login-failures:'.$tenants->id().':'.$hash;
            Cache::add($key, 0, now()->addHour());
            $count = (int) Cache::increment($key);

            if ($count === 20) {
                RepeatedLoginFailures::dispatch($tenants->id(), $hash, $count);
            }
        });
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Settings', 'Audit log', '/audit', 'shield', 90, ability: 'core.audit.view'));
        $nav->add(new NavItem('Settings', 'Roles', '/settings/roles', 'key-round', 80, ability: 'core.roles.manage'));
        $nav->add(new NavItem('Settings', 'Offices and teams', '/settings/organisation', 'building-2', 85, ability: 'core.organisation.manage'));
        $nav->add(new NavItem('Overview', 'Dashboard', '/dashboard', 'layout-grid', 10, kinds: [UserKind::Owner, UserKind::Staff], mobile: true));
        $nav->add(new NavItem('Overview', 'Today', '/agent', 'sun', 10, kinds: [UserKind::Agent, UserKind::Assistant], mobile: true));
        $nav->add(new NavItem('Brokerage', 'People', '/users', 'users', 10, ability: 'core.users.view', mobile: true));
        $nav->add(new NavItem('HQ', 'Tenants', '/hq/tenants', 'building-2', 10, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff], mobile: true));
        $nav->add(new NavItem('HQ', 'Texts and calls', '/hq/messaging', 'phone', 15, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
    }

    private function registerTenantAwareQueues(): void
    {
        Queue::createPayloadUsing(function (): array {
            $context = $this->app->make(TenantContext::class);

            return $context->check() ? ['tenant_id' => $context->id()] : [];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $tenantId = $this->tenantIdFrom($event->job);

            $this->app->make(TenantContext::class)->push(
                $tenantId !== null ? Tenant::query()->findOrFail($tenantId) : null,
            );
        });

        $restore = fn () => $this->app->make(TenantContext::class)->pop();
        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobFailed::class, $restore);
    }

    private function tenantIdFrom(Job $job): ?int
    {
        $payload = $job->payload();
        $id = $payload['tenant_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
