<?php

declare(strict_types=1);

namespace Modules\Core\Brief;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Onboarding\Setup;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Simple\SimpleMode;
use Modules\Crm\Brief\MorningList;
use Modules\Recruiting\SelfJoin;
use Modules\Roster\MlsIds;

/**
 * The daily brief on the home pages (specs/core/09): Dashboard for owners and staff, Today for agents and their
 * assistants (an assistant sees the brief of the agent they work for).
 */
final class BriefController
{
    public function dashboard(Request $request, DailyBriefs $briefs): Response|RedirectResponse
    {
        $user = self::user($request);
        if ($user->isKind(UserKind::HqStaff) && $request->user()?->can('hq.tenants.manage')) {
            return redirect('/hq/dashboard'); // HQ2: HQ staff land on the platform dashboard
        }
        if (SimpleMode::on($user)) {
            return redirect('/simple');
        }

        // MK2: owners see how far they are with "Get set up" until it is done.
        $setup = $user->isKind(UserKind::Owner) && class_exists(Setup::class) ? app(Setup::class)->for($user) : null;

        return Inertia::render('dashboard', ['firstName' => $user->first_name, 'brief' => $briefs->for($user), 'briefEmail' => self::emailOn($briefs, $user),
            'setup' => $setup !== null && $setup['done'] < $setup['total'] ? ['done' => $setup['done'], 'total' => $setup['total']] : null]);
    }

    public function agent(Request $request, DailyBriefs $briefs): Response|RedirectResponse
    {
        $user = self::user($request);
        if (SimpleMode::on($user)) {
            return redirect('/simple');
        }
        $for = $user->assistedAgent ?? $user;

        return Inertia::render('agent/home', ['firstName' => $user->first_name, 'assisting' => $user->assistedAgent?->name, 'brief' => $briefs->for($for),
            'briefEmail' => self::emailOn($briefs, $user),
            // SX6: the morning text with today's list (the CRM's; the agent's own opt-in).
            'morningText' => class_exists(MorningList::class) && $user->assistedAgent === null ? app(MorningList::class)->textOn($user) : null,
            // CM4: the agent's own link for bringing in other agents (they become the sponsor).
            'joinLink' => class_exists(SelfJoin::class) && $user->isKind(UserKind::Agent) ? $request->getSchemeAndHttpHost().'/join/'.app(SelfJoin::class)->codeFor($user) : null,
            // ML1: the agent's MLS ID, found in the MLS for them to confirm, or typed once.
            'mlsId' => class_exists(MlsIds::class) && $user->assistedAgent === null && ($user->isKind(UserKind::Agent) || $user->isKind(UserKind::Owner))
                ? app(MlsIds::class)->forAgent($user) : null]);
    }

    public function email(Request $request, DailyBriefs $briefs): RedirectResponse
    {
        $on = $request->boolean('on');
        $briefs->setEmail(self::user($request), $on);
        Inertia::flash('toast', ['type' => 'success', 'message' => $on ? 'You\'ll get your brief by email at 7am on weekdays.' : 'Morning email turned off.']);

        return back();
    }

    private static function emailOn(DailyBriefs $briefs, User $user): bool
    {
        try {
            return $briefs->emailOn($user);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
