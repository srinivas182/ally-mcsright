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

        // Plan changes recalculate pending deals automatically (best-in-class review, addition 7).
        Event::listen(PlansChanged::class, fn (PlansChanged $e) => $this->app->make(CommissionService::class)->recalculatePending($e->agentUserIds, $e->reason));

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCapPeriods::class]);
        }
    }
}
