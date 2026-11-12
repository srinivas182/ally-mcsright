<?php

declare(strict_types=1);

namespace Modules\Compensation;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\CommissionCalculator;
use Modules\Compensation\Console\CheckCapPeriods;
use Modules\Compensation\Events\PlansChanged;
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
        $this->app->scoped(CommissionService::class);
    }

    public function boot(): void
    {
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

        // Plan changes recalculate pending deals automatically (best-in-class review, addition 7).
        // Revenue share attribution and vesting follow people joining and leaving.
        Event::listen(UserActivated::class, [RevenueShare\Attribution::class, 'onActivated']);
        Event::listen(UserDeactivated::class, [RevenueShare\Attribution::class, 'onDeactivated']);

        Event::listen(PlansChanged::class, fn (PlansChanged $e) => $this->app->make(CommissionService::class)->recalculatePending($e->agentUserIds, $e->reason));

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCapPeriods::class]);
        }
    }
}
