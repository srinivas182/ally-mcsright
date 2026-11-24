<?php

declare(strict_types=1);

namespace Modules\Compensation\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Compensation\Enums\PlanStatus;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\Models\PlanAssignment;
use Modules\Compensation\Ranks\Ranks;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Compensation\RevenueShare\Unlocking;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;

/**
 * CM1: ranks (how agents move up, by production and recruits) and the brokerage's whole network tree (who
 * brought in whom).
 */
final class RanksController
{
    public function index(Request $request, Ranks $ranks, Unlocking $unlocking): Response
    {
        $user = self::can($request, 'compensation.plans.view');
        $now = CarbonImmutable::now();
        $plans = Plan::query()->where('type', PlanType::Commission)->where('status', PlanStatus::Active)->orderBy('name')->get(['id', 'ulid', 'name']);
        $current = $this->currentPlans();
        $agents = [];
        foreach (User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->where('status', UserStatus::Active)->orderBy('first_name')->get() as $a) {
            $p = $ranks->progress($a->id, $now, $unlocking);
            $target = $p['rank']['plan_id'] ?? null;
            $agents[] = [
                'ulid' => $a->ulid, 'name' => $a->name, 'rank' => $p['rank']['name'] ?? null, 'next' => $p['next']['name'] ?? null, 'needs' => $p['needs'],
                'closings' => $p['closings'], 'gci_cents' => $p['gci_cents'], 'recruits' => $p['recruits'],
                'plan' => isset($current[$a->id]) ? $plans->firstWhere('id', $current[$a->id])?->name : null,
                // Ready to move up: the rank names a plan the agent is not on yet (the broker decides).
                'move_to' => $target !== null && ($current[$a->id] ?? null) !== $target ? $plans->firstWhere('id', $target)?->only(['ulid', 'name']) : null,
            ];
        }

        return Inertia::render('compensation/ranks', [
            'ranks' => array_map(fn (array $r): array => $r + ['plan' => $r['plan_id'] !== null ? $plans->firstWhere('id', $r['plan_id'])?->ulid : null], $ranks->all()),
            'suggested' => Ranks::suggested(),
            'plans' => $plans->map(fn (Plan $p): array => ['ulid' => $p->ulid, 'name' => $p->name])->values(),
            'agents' => $agents,
            'canManage' => $user->can('compensation.plans.manage'),
        ]);
    }

    public function save(Request $request, Ranks $ranks): RedirectResponse
    {
        $user = self::can($request, 'compensation.plans.manage');
        $data = $request->validate([
            'ranks' => ['present', 'array', 'max:'.Ranks::MAX],
            'ranks.*.name' => ['required', 'string', 'max:60'],
            'ranks.*.min_closings' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'ranks.*.min_gci_cents' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'ranks.*.min_recruits' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'ranks.*.window_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'ranks.*.unlock_levels' => ['nullable', 'integer', 'min:0', 'max:15'],
            'ranks.*.plan' => ['nullable', 'string', 'size:26'],
        ]);
        $ranks->save($data['ranks'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ranks saved.']);

        return back();
    }

    /** The whole brokerage as a tree: everyone without a sponsor at the top, with the people they brought in below. */
    public function network(Request $request, Ranks $ranks, Unlocking $unlocking): Response
    {
        $viewer = self::can($request, 'compensation.plans.view');
        $now = CarbonImmutable::now();
        $people = User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->whereIn('status', [UserStatus::Active, UserStatus::Deactivated, UserStatus::Suspended])
            ->orderBy('first_name')->get()->keyBy('id');
        $sponsorOf = [];
        foreach (DB::connection(Connections::cell())->table('rs_sponsorships')->where('effective_from', '<=', $now->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $now->toDateString()))
            ->orderBy('effective_from')->get(['agent_user_id', 'sponsor_user_id']) as $s) {
            $sponsorOf[(int) $s->agent_user_id] = $s->sponsor_user_id !== null ? (int) $s->sponsor_user_id : null; // the latest start wins
        }
        $children = [];
        foreach ($people as $id => $p) {
            $sponsor = $sponsorOf[$id] ?? null;
            $children[$sponsor !== null && $people->has($sponsor) ? $sponsor : 0][] = (int) $id;
        }
        $from = $now->subMonths(12);
        $build = function (int $id, int $level, array $seen) use (&$build, $people, $children, $ranks, $unlocking, $now, $from): ?array {
            $u = $people->get($id);
            if (! $u instanceof User) {
                return null;
            }
            $kids = in_array($id, $seen, true) || $level > 15 ? [] : array_values(array_filter(array_map(fn (int $c): ?array => $build($c, $level + 1, [...$seen, $id]), $children[$id] ?? [])));
            $size = array_sum(array_map(fn (array $k): int => 1 + $k['team'], $kids));

            return ['ulid' => $u->ulid, 'name' => $u->name, 'active' => $u->status === UserStatus::Active, 'level' => $level,
                'rank' => $ranks->progress($id, $now, $unlocking)['rank']['name'] ?? null,
                'closings' => $unlocking->production($id, $from, $now)['closings'], 'recruits' => count($children[$id] ?? []), 'team' => $size, 'children' => $kids];
        };
        $tree = array_values(array_filter(array_map(fn (int $id): ?array => $build($id, 0, []), $children[0] ?? [])));
        usort($tree, fn (array $a, array $b): int => [$b['team'], $a['name']] <=> [$a['team'], $b['name']]);

        return Inertia::render('compensation/network', [
            'tree' => $tree,
            'totals' => ['people' => $people->count(), 'sponsored' => count(array_filter($sponsorOf)), 'ranks' => count($ranks->all())],
            'canChange' => $viewer->can('compensation.calculations.manage'),
            'people' => $people->filter(fn (User $u): bool => $u->status === UserStatus::Active)->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name])->values(),
        ]);
    }

    /**
     * Changes who brought an agent in, from today (history is kept; revenue share on deals already closed is not
     * changed). It moves future revenue share, so it needs the money permission, a reason and the password.
     */
    public function sponsor(Request $request, User $user, Sponsorships $sponsorships): RedirectResponse
    {
        $by = self::can($request, 'compensation.calculations.manage');
        $data = $request->validate(['sponsor' => ['nullable', 'string', 'size:26'], 'reason' => ['required', 'string', 'min:10', 'max:200']]);
        $sponsor = isset($data['sponsor']) ? User::query()->where('ulid', $data['sponsor'])->firstOrFail() : null;
        $sponsorships->set($user, $sponsor, CarbonImmutable::today(), $by, trim($data['reason']));
        Inertia::flash('toast', ['type' => 'success', 'message' => $sponsor !== null ? "{$user->name} now sits under {$sponsor->name}, from today." : "{$user->name} now sits at the top, from today."]);

        return back();
    }

    /**
     * @return array<int, int> agent id => current plan id
     */
    private function currentPlans(): array
    {
        $current = [];
        foreach (PlanAssignment::query()->where('plan_type', PlanType::Commission)->whereNull('effective_to')->orderBy('effective_from')->get(['agent_user_id', 'plan_id']) as $a) {
            $current[(int) $a->agent_user_id] = (int) $a->plan_id;
        }

        return $current;
    }

    private static function can(Request $request, string $permission): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can($permission), 403);

        return $user;
    }
}
