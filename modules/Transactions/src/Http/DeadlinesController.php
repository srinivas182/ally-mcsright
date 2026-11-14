<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Transactions\Deadlines\Deadlines;
use Modules\Transactions\Risk\RiskRegister;

/** Deadline alerts (agents' choices, calendar feed, the brokerage ladder) and the broker risk dashboard. */
final class DeadlinesController
{
    public function consent(Request $request, Deadlines $deadlines): RedirectResponse
    {
        $data = $request->validate(['sms' => ['required', 'boolean'], 'voice' => ['required', 'boolean']]);
        $deadlines->consent(self::user($request), (bool) $data['sms'], (bool) $data['voice']);

        return back();
    }

    public function calendar(Request $request, Deadlines $deadlines): RedirectResponse
    {
        $deadlines->calendarLink(self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your private calendar link is ready. Any older link no longer works.']);

        return back();
    }

    /** The agent's private calendar feed: the token in the link is the only key, so it is never shown in logs. */
    public function feed(string $token): HttpResponse
    {
        $userId = preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 ? DB::connection(Connections::cell())->table('tx_alert_prefs')->where('calendar_token', $token)->value('user_id') : null;
        abort_if($userId === null, 404);
        $user = User::query()->find((int) $userId);
        abort_if($user === null || ! $user->isActive(), 404);

        return response(Deadlines::ics((int) $userId), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, max-age=900']);
    }

    public function settings(Request $request, Deadlines $deadlines): RedirectResponse
    {
        abort_unless(self::reviewer(self::user($request)), 403);
        $data = $request->validate(['email_days' => ['required', 'integer'], 'text_days' => ['required', 'integer'], 'call_on_due' => ['required', 'boolean'],
            'broker_on_missed' => ['required', 'boolean'], 'closing_blocker_hours' => ['required', 'integer']]);
        $deadlines->saveSettings(['email_days' => (int) $data['email_days'], 'text_days' => (int) $data['text_days'], 'call_on_due' => (bool) $data['call_on_due'],
            'broker_on_missed' => (bool) $data['broker_on_missed'], 'closing_blocker_hours' => (int) $data['closing_blocker_hours']]);

        return back();
    }

    public function risk(Request $request): Response
    {
        abort_unless(self::reviewer(self::user($request)), 403);
        $risks = RiskRegister::all();

        return Inertia::render('deals/risk', ['risks' => $risks, 'trend' => RiskRegister::trend(),
            'counts' => ['high' => count(array_filter($risks, fn (array $r): bool => $r['level'] === 'high')), 'medium' => count(array_filter($risks, fn (array $r): bool => $r['level'] === 'medium'))]]);
    }

    public function export(Request $request): HttpResponse
    {
        abort_unless(self::reviewer(self::user($request)), 403);
        app(AuditLogger::class)->record('transactions.risk.exported', severity: 'notice');

        return response(RiskRegister::csv(), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="risk-register-'.now()->format('Y-m-d').'.csv"']);
    }

    private static function reviewer(User $user): bool
    {
        return $user->isKind(UserKind::Owner, UserKind::Staff) || $user->can('transactions.review');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
