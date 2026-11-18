<?php

declare(strict_types=1);

namespace Modules\Core\Brief;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Simple\SimpleMode;
use Modules\Crm\Brief\MorningList;

/**
 * The daily brief on the home pages (specs/core/09): Dashboard for owners and staff, Today for agents and their
 * assistants (an assistant sees the brief of the agent they work for).
 */
final class BriefController
{
    public function dashboard(Request $request, DailyBriefs $briefs): Response|RedirectResponse
    {
        $user = self::user($request);
        if (SimpleMode::on($user)) {
            return redirect('/simple');
        }

        return Inertia::render('dashboard', ['firstName' => $user->first_name, 'brief' => $briefs->for($user), 'briefEmail' => self::emailOn($briefs, $user)]);
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
            'morningText' => class_exists(MorningList::class) && $user->assistedAgent === null ? app(MorningList::class)->textOn($user) : null]);
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
