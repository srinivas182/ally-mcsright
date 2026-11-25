<?php

declare(strict_types=1);

namespace Modules\Core\Hq;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\HqAccess;
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

    /** HQ3: Today › To do. Everything waiting for HQ that this person's role covers, each with what to do and where. */
    public function today(Request $request, HqDashboard $dashboard): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('hq/today', ['items' => self::mine($user, $dashboard), 'at' => now()->toIso8601String()]);
    }

    /**
     * The To do items this person may act on (worked out at most once a minute for everyone).
     *
     * @return list<array{key: string, area: string, label: string, do: string, count: int, href: string}>
     */
    public static function mine(User $user, HqDashboard $dashboard): array
    {
        $all = Cache::remember('hq-todo', 60, fn (): array => $dashboard->todo());

        return array_values(array_filter($all, fn (array $i): bool => HqAccess::allows($user, "hq.{$i['area']}.see")));
    }
}
