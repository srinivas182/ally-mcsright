<?php

declare(strict_types=1);

namespace Modules\Compensation;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\CommissionCalculator;
use Modules\Compensation\Console\CheckCapPeriods;
use Modules\Compensation\Events\PlansChanged;
use Modules\Compensation\Ranks\Ranks;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Identity\Events\UserDeactivated;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class CompensationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ManagePlans::class);
        $this->app->scoped(PlanResolver::class);
        $this->app->singleton(CommissionCalculator::class);
        $this->app->scoped(CapPeriods::class);
        $this->app->scoped(Ranks::class);
        $this->app->scoped(Recruiters\Recruiters::class);
        $this->app->tag([Recruiters\RecruiterGuard::class], 'transactions.deal_guards');
        $this->app->scoped(CommissionService::class);
    }

    public function boot(): void
    {
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts'); // CP1
        $this->app->make(FeatureCatalog::class)->declare(PlanReader::FEATURE, 'ai.plan_reader', 'Commission plan set up from a document');
        // Permissions (specs/core/03); amounts are guarded by finance.amounts.view.
        $this->app->make(PermissionRegistry::class)->register('Compensation', [
            'compensation.plans.view' => ['See compensation plans'],
            'compensation.plans.manage' => ['Create, publish and assign compensation plans and fees'],
            'compensation.calculations.manage' => ['Calculate, finalise, reverse and exclude deals'],
            'compensation.credits.grant' => ['Give agents credits and bonuses'],
        ]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Compensation', 'Plans', '/compensation/plans', 'scale', 10, ability: 'compensation.plans.view'));
        $nav->add(new NavItem('Compensation', 'Fees', '/compensation/fees', 'receipt', 20, ability: 'compensation.plans.view'));
        $nav->add(new NavItem('Compensation', 'Credits', '/compensation/credits', 'gift', 30, ability: 'compensation.credits.grant'));
        $nav->add(new NavItem('Compensation', 'Simulator', '/compensation/simulator', 'calculator', 40, ability: 'compensation.plans.view'));
        $nav->add(new NavItem('Compensation', 'Profitability', '/compensation/profitability', 'trending-up', 50, ability: 'finance.amounts.view'));
        $nav->add(new NavItem('Overview', 'My plan', '/my-plan', 'badge-dollar-sign', 30, kinds: [UserKind::Agent], mobile: true));
        $nav->add(new NavItem('Overview', 'Revenue share', '/revenue-share', 'network', 35, kinds: [UserKind::Agent]));
        $nav->add(new NavItem('Compensation', 'Revenue share', '/compensation/revenue-share', 'network', 45, ability: 'compensation.calculations.manage'));
        $nav->add(new NavItem('Compensation', 'Ranks', '/compensation/ranks', 'award', 46, ability: 'compensation.plans.view'));
        $nav->add(new NavItem('Compensation', 'Network tree', '/compensation/network', 'git-fork', 47, ability: 'compensation.plans.view'));
        $nav->add(new NavItem('Compensation', 'Recruiters', '/compensation/recruiters', 'user-plus', 48, ability: 'compensation.plans.view'));

        // Plan changes recalculate pending deals automatically (best-in-class review, addition 7).
        // Revenue share attribution and vesting follow people joining and leaving.
        Event::listen(UserActivated::class, [RevenueShare\Attribution::class, 'onActivated']);
        Event::listen(UserActivated::class, [Recruiters\Recruiters::class, 'activated']);
        Event::listen(UserDeactivated::class, [RevenueShare\Attribution::class, 'onDeactivated']);

        Event::listen(PlansChanged::class, fn (PlansChanged $e) => $this->app->make(CommissionService::class)->recalculatePending($e->agentUserIds, $e->reason));

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCapPeriods::class]);
        }
    }
}
