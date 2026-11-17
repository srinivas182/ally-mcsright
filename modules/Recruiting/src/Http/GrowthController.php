<?php

declare(strict_types=1);

namespace Modules\Recruiting\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Recruiting\Joining;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Referrals;
use Modules\Recruiting\Results;
use Modules\Recruiting\Retention;
use Throwable;

/** RC3 (specs/grow/17): agents' referrals and note drafts, inviting recruits in, keeping agents, and results. */
final class GrowthController
{
    /** Any agent (and the owner): refer someone, see how your referrals are going. */
    public function refer(Request $request, Referrals $referrals): Response
    {
        $user = self::agent($request);

        return Inertia::render('recruiting/refer', [
            'suggestions' => $referrals->suggestions(), 'mine' => $referrals->mine($user), 'database' => $referrals->agentsSeeDatabase(),
            'broker' => $user->isKind(UserKind::Owner),
        ]);
    }

    public function storeReferral(Request $request, Referrals $referrals): RedirectResponse
    {
        $recruit = $referrals->refer(self::agent($request), $request->only(['name', 'email', 'phone', 'brokerage', 'note', 'mls_agent']));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Thank you! The brokerage will be in touch with {$recruit->name}."]);

        return back();
    }

    public function draft(Request $request, Referrals $referrals): JsonResponse
    {
        $user = self::agent($request);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:120']])['name'];
        try {
            return response()->json(['message' => $referrals->draft($user, $name)]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The writing helper is not available right now. Please write your own note.'], 422);
        }
    }

    public function databaseForAgents(Request $request, Referrals $referrals): RedirectResponse
    {
        abort_unless(self::agent($request)->isKind(UserKind::Owner), 403);
        $referrals->setAgentsSeeDatabase($request->boolean('on'));

        return back();
    }

    public function invite(Request $request, Recruit $recruit, Joining $joining): RedirectResponse
    {
        $joining->invite($recruit, self::recruiter($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Invitation sent to {$recruit->email}."]);

        return back();
    }

    public function retention(Request $request, Retention $retention): Response
    {
        self::recruiter($request);

        return Inertia::render('recruiting/retention', ['agents' => $retention->risks()]);
    }

    public function checkIn(Request $request, string $agent, Retention $retention): RedirectResponse
    {
        $by = self::recruiter($request);
        $user = User::query()->where('ulid', $agent)->where('kind', UserKind::Agent)->firstOrFail();
        $retention->checkIn($user, $by, $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Noted. {$user->first_name} is off this list for ".Retention::QUIET_DAYS.' days.']);

        return back();
    }

    public function results(Request $request, Results $results): Response
    {
        $user = self::recruiter($request);
        $year = max(2020, min((int) now()->year, (int) $request->query('year', (string) now()->year)));

        return Inertia::render('recruiting/results', $results->forYear($year) + ['canSeeMoney' => $user->can('finance.amounts.view') || $user->isKind(UserKind::Owner)]);
    }

    public function spend(Request $request, Results $results): RedirectResponse
    {
        self::recruiter($request);
        $d = $request->validate(['year' => ['required', 'integer'], 'source' => ['required', 'string'], 'amount' => ['required', 'numeric', 'min:0', 'max:10000000']]);
        $results->saveSpend((int) $d['year'], (string) $d['source'], (int) round((float) $d['amount'] * 100));

        return back();
    }

    private static function recruiter(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('recruiting.manage'), 403);

        return $user;
    }

    private static function agent(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isKind(UserKind::Agent) || $user->isKind(UserKind::Owner)), 403);

        return $user;
    }
}
