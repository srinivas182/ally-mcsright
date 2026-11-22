<?php

declare(strict_types=1);

namespace Modules\Core\Hq;

use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;

/** HQ2: the HQ console's home page, with the platform's numbers and what needs attention. */
#[CrossTenantAccess(HqDashboard::REASON)]
final class HqDashboardController
{
    public function __invoke(HqDashboard $dashboard): Response
    {
        // Figures across every brokerage: worked out at most once a minute, however many HQ staff have it open.
        return Inertia::render('hq/dashboard', Cache::remember('hq-dashboard', 60, fn (): array => $dashboard->build() + ['at' => now()->toIso8601String()]));
    }
}
