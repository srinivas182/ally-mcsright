<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Agents\AgentCredentials;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowRegistry;

final class RosterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AgentCredentials::class, RosterAgentCredentials::class);
        foreach ([Roster::class, LicenceGuard::class, Compliance::class, ExpiryReminders::class, Agreements::class, Flows::class, UpdateRequests::class, ContinuingEducation::class, Verification::class] as $service) {
            $this->app->scoped($service);
        }
        $this->app->tag([LicenceGuard::class], 'transactions.deal_guards');
        // Automatic verification is off until the ARELLO adapter is bound (with the data agreement).
        // LN4: the provider chosen in Outside services → Agent licence look-up.
        $this->app->bindIf(Verification\LicenceVerifier::class, Verification\ServiceVerifier::class);
    }

    public function boot(): void
    {
        // Agents in the workflow engine (specs/core/06, W1).
        $workflows = $this->app->make(WorkflowRegistry::class);
        $workflows->subject('agent', 'agent', fn (int $id): ?Subject => ($u = User::query()->find($id)) instanceof User ? new Subject('agent', $u->id, $u, $u->name, $u->id) : null);
        $workflows->trigger('agent.onboarding_started', 'An agent starts onboarding', 'agent', ['kind' => 'Type of person (agent, staff)']);
        $workflows->trigger('agent.offboarding_started', 'An agent starts leaving', 'agent', ['kind' => 'Type of person (agent, staff)', 'reason' => 'Why they are leaving']);
        $workflows->trigger('credential.expiring', 'A licence, E&O or membership is about to expire', 'agent',
            ['what' => 'What expires (licence, eo, association, mls)', 'state' => 'State (e.g. TX)', 'days_left' => 'Days left']);
        Brief\LicenceBriefItems::register($this->app->make(DailyBriefs::class));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\RosterReminders::class, Console\RosterVerify::class, Console\LoadLicenceData::class, Console\SyncLicenceData::class]);
        }

        $this->app->make(PermissionRegistry::class)->register('People', [
            'roster.manage' => ['Manage the agent roster: licences, E&O, memberships and documents'],
        ]);
        Gate::define('roster.view', fn (User $user): bool => Roster::manages($user));
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('People', 'Roster and compliance', '/roster', 'id-card', 12, ability: 'roster.view'));
        $nav->add(new NavItem('Overview', 'My licences and onboarding', '/roster/me', 'id-card', 33, kinds: [UserKind::Agent]));
        $nav->add(new NavItem('People', 'Directory', '/directory', 'users', 11, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
    }
}
