<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Signup\SignupReminders;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use stdClass;

/**
 * SU1: HQ › Customers › Sign-ups: everyone who started signing up, what they chose, and where they stopped. People
 * who did not finish paying can be sent a reminder with a link that brings their form back filled in.
 */
final class HqSignupsController
{
    public const STATUSES = ['pending' => 'Not paid yet', 'live' => 'Paid', 'failed' => 'Paid, set-up failed', 'replaced' => 'Started again'];

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), [...array_keys(self::STATUSES), 'all'], true) ? (string) $request->query('status') : 'pending';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $db = DB::connection(Connections::control());
        $rows = $db->table('signups')->leftJoin('billing_packages', 'billing_packages.id', '=', 'signups.package_id')
            ->when($status !== 'all', fn ($q) => $q->where('signups.status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('signups.brokerage', 'like', "%{$search}%")->orWhere('signups.owner_email', 'like', "%{$search}%")
                ->orWhere('signups.owner_name', 'like', "%{$search}%")->orWhere('signups.slug', 'like', "%{$search}%")))
            ->orderByDesc('signups.id')->limit(200)
            ->get(['signups.*', 'billing_packages.name as package_name', 'billing_packages.monthly_cents', 'billing_packages.yearly_cents']);

        return Inertia::render('hq/signups', [
            'status' => $status, 'q' => $search, 'statuses' => self::STATUSES,
            'counts' => $db->table('signups')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n),
            'signups' => $rows->map(fn (stdClass $s): array => [
                'ulid' => (string) $s->ulid, 'type' => $s->type === 'solo' ? 'Agent' : 'Brokerage', 'brokerage' => (string) $s->brokerage, 'slug' => (string) $s->slug,
                'owner' => (string) $s->owner_name, 'email' => (string) $s->owner_email, 'state' => $s->state, 'agents' => $s->agents !== null ? (int) $s->agents : null,
                'package' => $s->package_name, 'interval' => (string) $s->interval,
                'price_cents' => (int) ($s->interval === 'year' ? ($s->yearly_cents ?? 0) : ($s->monthly_cents ?? 0)),
                'boards' => count((array) json_decode((string) ($s->idx_boards ?? '[]'), true)),
                'status' => (string) $s->status, 'error' => $s->status === 'failed' ? $s->error : null,
                'started_at' => (string) $s->created_at, 'reminders' => (int) ($s->reminders_sent ?? 0), 'reminded_at' => $s->reminded_at ?? null,
                'no_reminders' => (bool) ($s->no_reminders ?? false),
                'resume_url' => $s->status === 'pending' ? SignupReminders::resumeUrl($s) : null,
            ])->values(),
        ]);
    }

    public function remind(string $signup, SignupReminders $reminders): RedirectResponse
    {
        $row = DB::connection(Connections::control())->table('signups')->where('ulid', $signup)->first() ?? abort(404);
        $sent = $reminders->send($row, byHq: true);
        if ($sent) {
            app(AuditLogger::class)->record('billing.signup.reminded', metadata: ['signup' => $signup], severity: 'info');
        }
        Inertia::flash('toast', $sent ? ['type' => 'success', 'message' => 'Reminder sent to '.$row->owner_email.'.']
            : ['type' => 'info', 'message' => 'No reminder sent: this sign-up is already paid, or that person has since signed up.']);

        return back();
    }
}
