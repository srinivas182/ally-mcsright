<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioResults;
use Modules\Core\Identity\Enums\UserKind;

/**
 * Studio → Results (specs/grow/13, SA4): a month of posts — reach, interactions, clicks and leads; what works by
 * format, style and time of day; the top posts; and what Autopilot has learned. Brokerage packages with team
 * reporting let owners and staff see the whole team, with each agent's line.
 */
final class StudioResultsController
{
    public function __invoke(Request $request, StudioResults $results, StudioPlans $plans): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) === 1 ? (string) $request->query('month') : CarbonImmutable::now()->format('Y-m');
        $canTeam = in_array($user->kind, [UserKind::Owner, UserKind::Staff], true) && $plans->has($user, 'team_report');
        $team = $canTeam && $request->boolean('team');
        $agents = [];
        if ($team) {
            $start = CarbonImmutable::parse($month.'-01');
            $ids = SocialPost::query()->whereBetween('posted_at', [$start, $start->endOfMonth()])->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all();
            foreach (User::query()->whereIn('id', $ids)->orderBy('first_name')->get() as $a) {
                $agents[] = ['name' => trim($a->first_name.' '.$a->last_name)] + $results->month($a, $month)['totals'];
            }
        }

        return Inertia::render('studio/results', [
            'ready' => $plans->current($user) !== null,
            'report' => $team ? $results->month($user, $month, array_values(array_map(fn ($id): int => (int) $id, SocialPost::query()->distinct()->pluck('user_id')->all())))
                : $results->month($user, $month),
            'learned' => $results->learned($user),
            'learn_after' => StudioResults::LEARN_AFTER,
            'can_team' => $canTeam,
            'team' => $team,
            'agents' => $agents,
            'months' => array_map(fn (int $i): string => CarbonImmutable::now()->startOfMonth()->subMonths($i)->format('Y-m'), range(0, 11)),
        ]);
    }
}
