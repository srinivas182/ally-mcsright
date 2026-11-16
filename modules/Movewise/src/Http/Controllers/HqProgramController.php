<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Crm\Actions\ManageContacts;
use Modules\Movewise\Matching\Connections;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\Partner;
use Modules\Movewise\Models\SeatRequest;
use Modules\Movewise\Models\State;
use Modules\Movewise\Outcomes\Invoices;
use Modules\Movewise\Program\Agents;
use Modules\Movewise\Program\Pricing;
use Modules\Movewise\Program\Seats;

/** HQ → Movewise → Agent program (specs/movewise/03): pricing per state, agent reviews, Movewise Agent seats. */
final class HqProgramController
{
    public function __construct(private readonly Agents $agents) {}

    public function index(): Response
    {
        $agents = Agent::query()->orderByRaw("case status when 'applying' then 0 when 'paused' then 1 when 'active' then 2 else 3 end")->orderBy('id')->limit(500)->get();
        $tenants = Tenant::query()->whereIn('id', $agents->pluck('tenant_id')->unique())->pluck('name', 'id');

        return Inertia::render('hq/movewise-program', [
            'models' => Pricing::MODELS,
            'states' => State::query()->orderBy('name')->get()->map(fn (State $s): array => ['code' => $s->code, 'name' => $s->name, 'on' => $s->on, 'pricing' => Pricing::for($s),
                'offered' => Pricing::offered($s), 'live_agents' => Agent::query()->where('state', $s->code)->where('status', 'active')->count(),
                'live_packages' => Package::query()->where('state', $s->code)->where('status', 'active')->count()])->values()->all(),
            'agents' => $agents->map(fn (Agent $a): array => ['ulid' => $a->ulid, 'account' => $tenants[$a->tenant_id] ?? '', 'state' => $a->state, 'licence_number' => $a->licence_number,
                'licence_status' => $a->licence_status, 'brokerage_name' => $a->brokerage_name, 'brokerage_confirmed' => $a->brokerage_confirmed_at !== null, 'seat' => $a->seat,
                'discipline_checked' => $a->discipline_checked_at !== null, 'profile' => $a->profile, 'profile_status' => $a->profile_status, 'review_note' => $a->review_note,
                'terms_signed' => $a->terms_signed_at !== null, 'status' => $a->status, 'pause_reason' => $a->pause_reason, 'tier' => $a->tier, 'strikes' => $a->strikes, 'scorecard' => $a->scorecard,
                'missing' => $this->agents->missing($a)])->values()->all(),
            'refunds' => Connection::query()->where('refund_status', 'requested')->orderBy('id')->limit(100)->get()->map(fn (Connection $c): array => ['ulid' => $c->ulid,
                'account' => Tenant::query()->whereKey($c->tenant_id)->value('name'), 'agent' => (string) (Agent::query()->find($c->agent_id)->profile['display_name'] ?? ''),
                'credits' => $c->price_credits, 'reason' => $c->refund_reason, 'method' => $c->method, 'accepted_at' => $c->accepted_at?->toIso8601String()])->values()->all(),
            'introductions' => ['offered' => Connection::query()->where('status', 'offered')->count(), 'accepted_30d' => Connection::query()->where('status', 'accepted')
                ->where('accepted_at', '>=', now()->subDays(30))->count(), 'expired_30d' => Connection::query()->where('status', 'expired')->where('offered_at', '>=', now()->subDays(30))->count()],
            'relays' => DB::connection(ControlDb::control())->table('movewise_relay_numbers')->orderBy('number')->get()->map(fn ($r): array => ['id' => (int) $r->id,
                'number' => (string) $r->number, 'area' => $r->area, 'active' => (bool) $r->active, 'in_use' => Connection::query()->where('relay_number', $r->number)->where('status', 'accepted')
                    ->whereNull('handed_over_at')->count()])->values()->all(),
            'seats' => SeatRequest::query()->orderByRaw("case status when 'pending' then 0 else 1 end")->orderByDesc('id')->limit(200)->get()->map(fn (SeatRequest $r): array => [
                'ulid' => $r->ulid, 'name' => $r->first_name.' '.$r->last_name, 'email' => $r->email, 'state' => $r->state, 'licence_number' => $r->licence_number,
                'brokerage_name' => $r->brokerage_name, 'licence_status' => $r->licence_status, 'status' => $r->status, 'note' => $r->note])->values()->all(),
        ]);
    }

    public function pricing(Request $request, string $state, Pricing $pricing): RedirectResponse
    {
        $pricing->save(State::query()->findOrFail($state), $request->all(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Prices saved. They apply to new introductions only.']);

        return back();
    }

    public function review(Request $request, string $agent): RedirectResponse
    {
        $this->agents->review(Agent::query()->where('ulid', $agent)->firstOrFail(), $request->all(), self::user($request));

        return back();
    }

    /** Pause or remove an agent (HQ only); either also pauses their packages. Resuming re-runs every check. */
    public function status(Request $request, string $agent): RedirectResponse
    {
        $a = Agent::query()->where('ulid', $agent)->firstOrFail();
        $to = (string) $request->input('status');
        abort_unless(in_array($to, ['paused', 'removed', 'applying'], true), 422);
        $reason = trim(mb_substr((string) $request->input('reason', ''), 0, 200));
        abort_if($to !== 'applying' && $reason === '', 422, 'Give a reason.');
        $a->forceFill(['status' => $to, 'pause_reason' => $to === 'applying' ? null : $reason])->save();
        if ($to !== 'applying') {
            Package::query()->where('agent_id', $a->id)->where('status', 'active')->update(['status' => 'paused', 'pause_reason' => 'Paused by Movewise: '.$reason]);
        }
        $this->agents->refresh($a);
        app(AuditLogger::class)->record('movewise.agent.'.$to, $a, metadata: ['reason' => $reason, 'by' => self::user($request)->id], severity: 'warning', tenantId: $a->tenant_id);

        return back();
    }

    public function seat(Request $request, string $seat, Seats $seats): RedirectResponse
    {
        $r = SeatRequest::query()->where('ulid', $seat)->firstOrFail();
        $request->boolean('approve') ? $seats->approve($r, self::user($request)) : $seats->decline($r, (string) $request->input('note', ''), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('approve') ? 'Approved. They\'ve been emailed an invitation to their new account.' : 'Declined.']);

        return back();
    }

    public function refund(Request $request, string $connection, Connections $connections): RedirectResponse
    {
        $connections->resolve(Connection::query()->where('ulid', $connection)->firstOrFail(), $request->boolean('approve'), self::user($request));

        return back();
    }

    /** HQ → Movewise money (specs/movewise/06): revenue by state and model, referral invoices, partner settlements, quality. */
    public function money(): Response
    {
        $db = DB::connection(ControlDb::control());
        $since = now()->subDays(90);
        $rows = Connection::query()->where('offered_at', '>=', $since)->get();
        $agents = Agent::query()->whereIn('id', $rows->pluck('agent_id')->unique())->pluck('state', 'id');
        $revenue = [];
        foreach ($rows as $c) {
            $key = ($agents[$c->agent_id] ?? '?').'|'.$c->model;
            $revenue[$key] ??= ['state' => $agents[$c->agent_id] ?? '?', 'model' => $c->model, 'introductions' => 0, 'accepted' => 0, 'credits' => 0, 'refunded' => 0];
            $revenue[$key]['introductions']++;
            $revenue[$key]['accepted'] += $c->accepted_at !== null ? 1 : 0;
            $revenue[$key]['credits'] += $c->refund_status === 'refunded' ? 0 : $c->price_credits;
            $revenue[$key]['refunded'] += $c->refund_status === 'refunded' ? $c->price_credits : 0;
        }
        $partners = Partner::query()->pluck('name', 'id');

        return Inertia::render('hq/movewise-money', [
            'revenue' => array_values($revenue),
            'quality' => ['accepted' => $rows->whereNotNull('accepted_at')->count(), 'stopped' => $rows->whereIn('status', ['stopped', 'changed', 'withdrawn'])->count(),
                'rating' => $rows->whereNotNull('rating')->avg('rating'), 'ratings' => $rows->whereNotNull('rating')->count(),
                'closed' => $rows->where('stage', 'closed')->count()],
            'invoices' => $db->table('movewise_invoices')->orderByRaw("case status when 'issued' then 0 else 1 end")->orderByDesc('id')->limit(200)->get()
                ->map(fn ($i): array => ['ulid' => (string) $i->ulid, 'number' => (string) $i->number, 'account' => Tenant::query()->whereKey($i->tenant_id)->value('name'),
                    'partner' => $partners[$i->partner_id] ?? '', 'amount_cents' => (int) $i->amount_cents, 'share_pct' => (float) $i->share_pct, 'commission_cents' => (int) $i->commission_cents,
                    'status' => (string) $i->status, 'due_on' => (string) $i->due_on, 'overdue' => $i->status === 'issued' && (string) $i->due_on < now()->toDateString()])->all(),
            'settlements' => $db->table('movewise_settlements')->orderByDesc('period')->limit(100)->get()->map(fn ($s): array => ['id' => (int) $s->id, 'partner' => $partners[$s->partner_id] ?? '',
                'period' => (string) $s->period, 'invoices' => (int) $s->invoices, 'collected_cents' => (int) $s->collected_cents, 'hq_pct' => (float) $s->hq_pct,
                'hq_cents' => (int) $s->hq_cents, 'status' => (string) $s->status])->all(),
        ]);
    }

    public function invoice(Request $request, string $invoice, Invoices $invoices): RedirectResponse
    {
        $invoices->mark($invoice, (string) $request->input('status'), self::user($request), $request->input('note'));

        return back();
    }

    public function settlement(Request $request, int $settlement): RedirectResponse
    {
        DB::connection(ControlDb::control())->table('movewise_settlements')->where('id', $settlement)->where('status', 'open')
            ->update(['status' => 'settled', 'settled_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('movewise.settlement.settled', metadata: ['settlement' => $settlement, 'by' => self::user($request)->id]);

        return back();
    }

    /** A strike for breaking the program rules; the third removes the agent (specs/movewise/00 §6.3). */
    public function strike(Request $request, string $agent): RedirectResponse
    {
        $a = Agent::query()->where('ulid', $agent)->firstOrFail();
        $reason = trim(mb_substr((string) $request->input('reason', ''), 0, 200));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for the strike.']);
        }
        $a->forceFill(['strikes' => $a->strikes + 1])->save();
        if ($a->strikes >= 3) {
            $a->forceFill(['status' => 'removed', 'pause_reason' => 'Three strikes: '.$reason])->save();
            Package::query()->where('agent_id', $a->id)->where('status', 'active')->update(['status' => 'paused', 'pause_reason' => 'Removed from Movewise']);
        }
        app(AuditLogger::class)->record('movewise.agent.strike', $a, metadata: ['reason' => $reason, 'strikes' => $a->strikes, 'by' => self::user($request)->id], severity: 'warning',
            tenantId: $a->tenant_id);

        return back();
    }

    /** HQ's pool of relay numbers (Twilio numbers in HQ's account, pointed at the texts webhook). */
    public function relay(Request $request): RedirectResponse
    {
        $db = DB::connection(ControlDb::control())->table('movewise_relay_numbers');
        if ($request->filled('id')) {
            $db->where('id', (int) $request->input('id'))->update(['active' => $request->boolean('active'), 'updated_at' => now()]);

            return back();
        }
        $number = ManageContacts::e164((string) $request->input('number', ''));
        if ($number === null) {
            throw ValidationException::withMessages(['number' => 'Enter a US number from HQ\'s Twilio account.']);
        }
        $db->insertOrIgnore(['number' => $number, 'area' => mb_substr(trim((string) $request->input('area', '')), 0, 60) ?: null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('movewise.relay.added', metadata: ['number' => $number, 'by' => self::user($request)->id]);

        return back();
    }

    private static function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
}
