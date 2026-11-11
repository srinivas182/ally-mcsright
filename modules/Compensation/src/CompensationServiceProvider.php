<?php

declare(strict_types=1);

namespace Modules\Compensation;

use Illuminate\Support\ServiceProvider;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\CommissionCalculator;

final class CompensationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ManagePlans::class);
        $this->app->scoped(PlanResolver::class);
        $this->app->singleton(CommissionCalculator::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
