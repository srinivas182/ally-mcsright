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
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Modules\Core\Access\HqAccess;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Access\Permissions;
use Modules\Core\Access\Roles;
use Modules\Core\Agents\AgentCredentials;
use Modules\Core\Agents\NoAgentCredentials;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Branding\BrandColourSource;
use Modules\Core\Branding\EmailSignature;
use Modules\Core\Branding\NullBrandColourSource;
use Modules\Core\Branding\PlainEmailSignature;
use Modules\Core\Console\BootstrapHq;
use Modules\Core\Console\CompileDesignTokens;
use Modules\Core\Console\RunTenantLifecycle;
use Modules\Core\Deals\DealOutcomes;
use Modules\Core\Deals\ListingActivity;
use Modules\Core\Deals\NoDealOutcomes;
use Modules\Core\Deals\NoListingActivity;
use Modules\Core\Identity\Auth\TenantPasswordBrokerManager;
use Modules\Core\Identity\Auth\TenantUserProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Events\ImpersonationStarted;
use Modules\Core\Identity\Events\ImpersonationStopped;
use Modules\Core\Identity\Events\RepeatedLoginFailures;
use Modules\Core\Identity\Impersonation;
use Modules\Core\Identity\Security\SignInSecurity;
use Modules\Core\Identity\Security\TrustedDevices;
use Modules\Core\Leads\NoVisitorJourneys;
use Modules\Core\Leads\VisitorJourneys;
use Modules\Core\Mail\MailBrand;
use Modules\Core\Mail\PersonalMailChannel;
use Modules\Core\Mail\PlatformEmail;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Safeguards\BrokerageKeys;
use Modules\Core\Safeguards\DedicatedCells;
use Modules\Core\Tenancy\CellRouting;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Exceptions\MissingTenantContext;
use Modules\Core\Tenancy\Models\Cell;
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
        // Sprint 58: every database connection's query builder limits brokerage queries to the brokerage on its
        // cell (Tenancy\Guard\TenantGuard), so a forgotten "where tenant_id" in a DB::table() query cannot leak.
        Connection::resolverFor('sqlite', fn ($pdo, $db, $prefix, $config) => new Tenancy\Guard\GuardedSQLiteConnection($pdo, $db, $prefix, $config));
        Connection::resolverFor('mysql', fn ($pdo, $db, $prefix, $config) => new Tenancy\Guard\GuardedMySqlConnection($pdo, $db, $prefix, $config));
        Connection::resolverFor('mariadb', fn ($pdo, $db, $prefix, $config) => new Tenancy\Guard\GuardedMariaDbConnection($pdo, $db, $prefix, $config));
        Connection::resolverFor('pgsql', fn ($pdo, $db, $prefix, $config) => new Tenancy\Guard\GuardedPostgresConnection($pdo, $db, $prefix, $config));

        // Texts and calls use the Twilio account HQ set up (falling back to the server's settings); scoped so a
        // change in HQ settings applies to the next request or job.
        $this->app->singleton(Messaging\MessagingSettings::class);
        $this->app->bindIf(DealOutcomes::class, NoDealOutcomes::class);
        $this->app->bindIf(ListingActivity::class, NoListingActivity::class);
        $this->app->bindIf(Deals\BuyerAgreementGuard::class, Deals\NoBuyerAgreementGuard::class); // AP1
        $this->app->bindIf(Learning\TrainingGate::class, Learning\NoTrainingGate::class); // LN2
        $this->app->bindIf(EmailSignature::class, PlainEmailSignature::class);
        $this->app->bindIf(VisitorJourneys::class, NoVisitorJourneys::class);
        $this->app->bindIf(AgentCredentials::class, NoAgentCredentials::class);
        $this->app->bindIf(Usage\WebsiteMaps::class, Usage\UnlimitedWebsiteMaps::class);
        $this->app->bindIf(Credits\CreditSpend::class, Credits\NoCreditSpend::class);
        // Connect (specs/connect/00): the app catalog every module adds to, and events for outgoing webhooks.
        $this->app->singleton(Connect\AppCatalog::class);
        $this->app->bindIf(Connect\Outbox::class, Connect\NoOutbox::class);
        $this->app->bindIf(Connect\Calendars::class, Connect\NoCalendars::class);
        $this->app->bindIf(Listings\MlsStatus::class, Listings\NoMlsStatus::class);
        $this->app->singleton(Undo\Undo::class); // modules register how to put each kind of action back
        // MCP (specs/connect/03): the tools modules offer to people's AI assistants; off unless Connect allows it.
        $this->app->singleton(Mcp\McpToolbox::class);
        $this->app->singleton(Brief\DailyBriefs::class);
        $this->app->bindIf(Mcp\McpGate::class, fn (): Mcp\McpGate => new class implements Mcp\McpGate
        {
            public function allowed(User $user): bool
            {
                return false;
            }

            public function writesAllowed(User $user): bool
            {
                return false;
            }
        });
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
        // FL1 (specs/core/16): areas, switches and seats; approval rules; outside services.
        $this->app->singleton(Switches\Areas::class);
        $this->app->singleton(Search\SearchRegistry::class); // PW1: universal search
        $this->app->singleton(Privacy\PrivacyRegistry::class); // TR1: where personal data lives
        $this->app->singleton(Assistant\Handoffs::class); // AS1: the assistant hands a conversation to a person
        $this->app->scoped(Identity\AgentAssistants::class); // AG1: agents' own assistants
        $this->app->scoped(Privacy\PrivacyRequests::class);
        $this->app->scoped(Privacy\Retention::class);
        $this->app->scoped(Switches\FeatureSwitches::class);
        $this->app->singleton(Approvals\ApprovalActions::class);
        $this->app->scoped(Approvals\Approvals::class);
        $this->app->singleton(Notices\NoticeTypes::class);
        $this->app->scoped(CustomFields\CustomFields::class);
        $this->app->singleton(Workflows\WorkflowRegistry::class);
        $this->app->scoped(Workflows\Workflows::class);
        $this->app->bindIf(Workflows\WorkflowDrafter::class, Workflows\NoWorkflowDrafter::class);
        $this->mergeConfigFrom(__DIR__.'/../config/core.php', 'core');

        // Scoped: reset between requests (Octane) and between queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(CellRouting::class); // DS1: per-request cell routing
        $this->app->singleton(BrokerageKeys::class); // DS2: per-brokerage keys (columns registered by modules)
        $this->app->make(CellRouting::class)->resolveWith(fn (Cell $cell): ?string => DedicatedCells::register($cell)); // DS2: dedicated databases
        $this->app->scoped(TenantCache::class);
        $this->app->singleton(DomainResolver::class);
        $this->app->bind(BrandColourSource::class, NullBrandColourSource::class);
        $this->app->bindIf(Branding\OfficeBrandSource::class, Branding\NullOfficeBrandSource::class); // BR2: Sites supplies the real one
    }

    /**
     * EM1: RightAlly's own email goes through the provider HQ switches on in Outside services (PlatformEmail); when
     * none is on, through the server's MAIL_MAILER as before. Tests (the "array" mailer) are left alone.
     */
    private function registerPlatformEmail(): void
    {
        Mail::extend('platform', fn (array $config): PlatformEmail => new PlatformEmail((string) ($config['fallback'] ?? 'log')));
        $server = (string) config('mail.default');
        if (! in_array($server, ['array', 'platform'], true)) {
            config(['mail.mailers.platform' => ['transport' => 'platform', 'fallback' => $server], 'mail.default' => 'platform']);
        }
    }

    public function boot(): void
    {
        // EM3: every email shows who it is from: the brokerage's logo and colour, or RightAlly's.
        // EM3: notification emails greet people by their first name.
        $this->app->bind(MailChannel::class, PersonalMailChannel::class);
        View::composer(['mail::*', 'notifications::email'], fn ($view) => $view->with('mailBrand', MailBrand::current()));
        $this->app->make(Mcp\McpToolbox::class)->add(Mcp\DailyBrief::class); // sections come from each module
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'core');
        $this->loadRoutesFrom(__DIR__.'/../routes/hq.php');

        $this->loadRoutesFrom(__DIR__.'/../routes/identity.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/platform.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/shell.php'); // PW1: installable app and universal search
        $this->registerIdentity();
        $this->registerNavigation();
        $this->registerPlatform();
        $this->registerPlatformEmail();

        $this->registerTenantAwareQueues();
        $this->registerMigrationMacros();

        // The notification centre (specs/core/10, N1): every email alert to a person also lands in their inbox, and
        // their preferences decide whether and when the email goes.
        Event::listen(NotificationSending::class, [Notices\CaptureEmailNotices::class, 'handle']);
        $types = $this->app->make(Notices\NoticeTypes::class);
        $types->register(Access\AccessAlertMail::class, 'Access changes (someone given access to money, people or exports)', 'Security', urgent: true);
        $types->register(Brief\DailyBriefMail::class, 'Your daily brief', 'General');
        $types->register(Workflows\WorkflowAlertMail::class, 'Alerts from automations', 'Automations');
        $types->register(Workflows\WorkflowApprovalMail::class, 'Approvals automations are waiting for', 'Automations', urgent: true);
        $types->register(Privacy\PrivacyRequestMail::class, 'Privacy requests from the public', 'Security', urgent: true); // TR1
        $types->register(Privacy\PrivacyDueMail::class, 'Privacy requests due soon', 'Security', urgent: true); // TR1
        $types->register(Approvals\ApprovalNeededMail::class, 'Sensitive changes waiting for your approval', 'Security', urgent: true); // FL1
        $types->register(Identity\Notifications\SupportAccessRequested::class, 'RightAlly support asking to view your account', 'Security', urgent: true); // FL1

        // The workflow engine (specs/core/06, W1): Core's own steps and triggers; modules add theirs.
        $workflows = $this->app->make(Workflows\WorkflowRegistry::class);
        $workflows->action(new Workflows\Actions\Notify);
        $workflows->action(new Workflows\Actions\SetField);
        foreach (['contact' => 'A contact', 'deal' => 'A deal', 'agent' => 'An agent'] as $entity => $what) {
            $workflows->trigger($entity.'.field_changed', $what."'s custom field changes", $entity, ['field' => 'Which field (its key)', 'value' => 'The new value']);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CompileDesignTokens::class, RunTenantLifecycle::class, BootstrapHq::class, Console\FlushAudit::class, Console\VerifyAudit::class, Console\AuditMaintenance::class, Console\ExpireAccess::class, Console\AccessReviewReminder::class, Console\SendNoticeDigests::class, Console\RunWorkflows::class, Console\RebuildDirectory::class, Console\SendDailyBriefs::class, Safeguards\Console\ExpireExports::class, Safeguards\Console\SafeguardsDaily::class, Safeguards\Console\Rekey::class, Scale\Console\WatchCells::class, Scale\Console\SeedLoadTest::class, Scale\Console\SeedEndToEnd::class, Console\ExpireApprovals::class, Console\PrivacyDaily::class, Launch\LaunchCheck::class]);
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
            'core.access.review' => ['Run the quarterly access review and export who can do what', false, true],
            'core.fields.manage' => ['Add and change custom fields on contacts, deals and agents'],
            'core.workflows.manage' => ['Create and switch on automations'],
            'core.platform.manage' => ['Switch areas on or off, give seats and connect outside services (keys, Test and Live)'],
            'core.approvals.give' => ['Approve sensitive changes other people ask for'],
            'core.privacy.manage' => ['Answer privacy requests (see, delete, correct, stop sharing)', false, true],
        ]);
        // Areas whose screens live in several modules (specs/core/03, R1).
        // HQ2: the HQ console's permissions (only HQ's roles use them).
        $registry->register('hq', HqAccess::permissions());
        $registry->register('Marketing', [
            'marketing.manage' => ['Run company marketing: Ally Studio and Autopilot, social posts, listing ads, funnels, buyer demand and campaigns'],
        ]);
        $registry->register('Apps', [
            'apps.manage' => ['Connect and disconnect apps for the whole brokerage'],
        ]);
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $registry = $this->app->make(PermissionRegistry::class);
            if (! $registry->has($ability)) {
                return null;
            }
            $record = $arguments[0] ?? null;

            return $this->app->make(Permissions::class)->allows($user, $ability, $record instanceof Model ? $record : null);
        });

        // The shared AI assistant address finds people's brokerage by email (specs/connect/03, M2b).
        User::saved(function (User $u): void {
            if ($u->wasRecentlyCreated || $u->wasChanged(['email', 'kind', 'deleted_at'])) {
                Identity\Directory::sync($u);
            }
        });
        User::deleted(fn (User $u) => Identity\Directory::forget($u));
        User::restored(fn (User $u) => Identity\Directory::sync($u));

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

    /**
     * FL1 (specs/core/16): the areas a brokerage (or HQ) can switch on or off, which can be sold by the seat, and the
     * settings changes that can need approval. Modules may add their own areas and approval actions the same way.
     */
    private function registerPlatform(): void
    {
        // PW1 (specs/core/17): people in universal search, for those who may see the brokerage's people.
        $this->app->make(Search\SearchRegistry::class)->register('people', ['en' => 'People', 'es' => 'Personas'], 40,
            function (User $user, string $q, int $limit): array {
                if (! Gate::forUser($user)->allows('core.users.view')) {
                    return [];
                }
                $like = Search\SearchController::like($q);

                return array_values(User::query()->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('email', 'like', mb_strtolower($like)))
                    ->whereNot('kind', UserKind::HqStaff)->orderBy('first_name')->limit($limit)->get()
                    ->map(fn (User $u): array => ['title' => $u->name, 'subtitle' => $u->email, 'href' => '/users?search='.rawurlencode($u->email)])->all());
            });

        $areas = $this->app->make(Switches\Areas::class);
        foreach ([
            ['ads', 'Ads', 'Listing ads, Boost and Google ads', ['/ads'], true],
            ['studio', 'Ally Studio', 'Branded videos, posts and flyers made with AI', ['/studio'], true],
            ['appointments', 'Appointments', 'Guided listing appointments and buyer consultations', ['/appointments'], false],
            ['movewise', 'Movewise', 'Leads from Movewise Homes, RightAlly\'s home-search site', ['/movewise'], false],
            ['network', 'Referral network', 'Referrals with agents at other RightAlly brokerages', ['/network', '/settings/network'], false],
            ['recruiting', 'Recruiting', 'Find, invite and keep agents', ['/recruiting'], false],
            ['learn', 'Learn', 'Training, Learn-IQ coach and role-play', ['/learn'], true],
            ['prospector', 'Prospector', 'Finds likely sellers and lists to call', ['/prospector'], true],
            ['postcards', 'Postcards', 'Printed postcards and handwritten cards', ['/postcards'], false],
            ['social', 'Social posts', 'Scheduled posts to Facebook, Instagram and LinkedIn', ['/social'], false],
            ['reviews', 'Reviews', 'Asking clients for reviews and showing them', ['/reviews'], false],
            ['funnels', 'Funnels', 'Landing pages that capture leads', ['/funnels'], false],
            ['list_iq', 'List-IQ', 'Listing marketing: photos, staging, videos and the listing draft', ['/list-iq'], true],
            ['outreach', 'Outreach', 'Texting and calling campaigns with consent checks', ['/outreach'], false],
            ['engage', 'Game plan', 'Agent goals, activity and recognition', ['/engage'], false],
            ['chat', 'Team chat', 'Chat and help between agents and staff', ['/chat'], false],
            ['revenue_share', 'Revenue share', 'Revenue share for agents who bring in agents', ['/revenue-share', '/compensation/revenue-share'], false],
            ['buyer_demand', 'Buyer demand', 'Buyers waiting for homes before a listing goes live', ['/listings/demand'], false],
            ['pricing', 'CMA and estimates', 'Comparable sales and price estimates', ['/pricing'], false],
        ] as [$key, $label, $about, $paths, $seats]) {
            $areas->register($key, $label, $about, $paths, $seats);
        }

        $this->app->make(Approvals\Approvals::class)->register('services.go_live', 'Switch an outside service to Live (real people and real accounts)',
            function (array $payload, User $by): string {
                $service = (string) ($payload['service'] ?? '');
                $this->app->make(Services\OutsideServices::class)->setMode($this->app->make(TenantContext::class)->id(), $service, 'live', $by);

                return (Services\Catalog::get($service)['label'] ?? $service).' is now Live.';
            }, default: 0);
        // AG1: agents adding their own assistants (no approval unless the owner sets one).
        $this->app->make(Approvals\Approvals::class)->register('agents.add_assistant', 'An agent adds their own assistant',
            fn (array $payload, User $by): string => $this->app->make(Identity\AgentAssistants::class)->send($payload), default: 0);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Settings', 'Audit log', '/audit', 'shield', 90, ability: 'core.audit.view'));
        $nav->add(new NavItem('Settings', 'Roles', '/settings/roles', 'key-round', 80, ability: 'core.roles.manage'));
        Gate::define('core.access.reviewer', fn (User $user): bool => $user->kind === UserKind::Owner || $this->app->make(Permissions::class)->allows($user, 'core.access.review'));
        Gate::define('core.workflows.admin', fn (User $user): bool => Workflows\Workflows::manages($user));
        $nav->add(new NavItem('Settings', 'Automations', '/automations', 'workflow', 78, ability: 'core.workflows.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        Gate::define('core.fields.admin', fn (User $user): bool => CustomFields\CustomFields::manages($user));
        $nav->add(new NavItem('Settings', 'Custom fields', '/settings/custom-fields', 'list-plus', 79, ability: 'core.fields.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Settings', 'Access review', '/settings/access-review', 'shield-check', 81, ability: 'core.access.reviewer'));
        $nav->add(new NavItem('Settings', 'Offices and teams', '/settings/organisation', 'building-2', 85, ability: 'core.organisation.manage'));
        $nav->add(new NavItem('Overview', 'Dashboard', '/dashboard', 'layout-grid', 10, kinds: [UserKind::Owner, UserKind::Staff], mobile: true));
        $nav->add(new NavItem('Overview', 'Today', '/agent', 'sun', 10, kinds: [UserKind::Agent, UserKind::Assistant], mobile: true));
        $nav->add(new NavItem('Brokerage', 'People', '/users', 'users', 10, ability: 'core.users.view', mobile: true));
        $nav->add(new NavItem('Overview', 'To do', '/hq/today', 'list-checks', 9, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff], mobile: true)); // HQ3
        $nav->add(new NavItem('Overview', 'Dashboard', '/hq/dashboard', 'layout-grid', 10, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff], mobile: true)); // HQ2
        $nav->add(new NavItem('Customers', 'Brokerages', '/hq/tenants', 'building-2', 10, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff], mobile: true));
        $nav->add(new NavItem('Connections', 'Texts and calls', '/hq/messaging', 'phone', 11, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('System', 'Cells', '/hq/cells', 'database', 11, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // PS1
        $nav->add(new NavItem('System', 'Feature switches', '/hq/switches', 'toggle-right', 12, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // FL1
        $nav->add(new NavItem('Connections', 'Outside services', '/hq/services', 'plug', 10, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // FL1
        $nav->add(new NavItem('Settings', 'HQ roles', '/hq/roles', 'shield-check', 11, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // HQ2
        $nav->add(new NavItem('System', 'Security controls', '/hq/controls', 'shield-check', 13, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // TR1
        $nav->add(new NavItem('Settings', 'Features and seats', '/settings/features', 'toggle-right', 82, ability: 'core.platform.manage')); // FL1
        $nav->add(new NavItem('Settings', 'Outside services', '/settings/services', 'plug', 83, ability: 'core.platform.manage')); // FL1
        $nav->add(new NavItem('Settings', 'Approvals', '/settings/approvals', 'check-check', 84, kinds: [UserKind::Owner, UserKind::Staff])); // FL1
        $nav->add(new NavItem('Settings', 'Support access', '/settings/support-access', 'life-buoy', 86, kinds: [UserKind::Owner])); // FL1
        $nav->add(new NavItem('Settings', 'My assistants', '/my-assistants', 'users', 88, kinds: [UserKind::Agent])); // AG1
        $nav->add(new NavItem('Settings', 'Privacy requests', '/settings/privacy', 'shield-check', 87, ability: 'core.privacy.manage', kinds: [UserKind::Owner, UserKind::Staff])); // TR1
    }

    private function registerTenantAwareQueues(): void
    {
        Queue::createPayloadUsing(function (): array {
            $context = $this->app->make(TenantContext::class);

            return $context->check() ? ['tenant_id' => $context->id()] : [];
        });

        // SX2: every attempt pushes exactly once (before the job) and pops exactly once (JobAttempted, fired after
        // every attempt: done, failed, released for a retry or failed by the job itself). Popping on JobProcessed and
        // JobFailed as before could pop twice (a job that calls fail()) and, on the sync queue, drop the request's
        // own brokerage, or never pop (a job released for a retry).
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $tenantId = $this->tenantIdFrom($event->job);
            $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;
            $this->app->make(TenantContext::class)->push($tenant);
            if ($tenantId !== null && $tenant === null) {
                // The brokerage is gone: never run its job without a brokerage (that would be unguarded).
                throw MissingTenantContext::forJob($event->job->resolveName(), $tenantId);
            }
        });

        Event::listen(JobAttempted::class, fn () => $this->app->make(TenantContext::class)->pop());
    }

    private function tenantIdFrom(Job $job): ?int
    {
        $payload = $job->payload();
        $id = $payload['tenant_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
