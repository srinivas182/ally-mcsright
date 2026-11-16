<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\SeatRequest;
use Modules\Movewise\Models\State;
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
                'terms_signed' => $a->terms_signed_at !== null, 'status' => $a->status, 'pause_reason' => $a->pause_reason, 'tier' => $a->tier, 'strikes' => $a->strikes,
                'missing' => $this->agents->missing($a)])->values()->all(),
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

    private static function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
}
