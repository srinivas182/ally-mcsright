<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Bridge\Relay;
use Modules\Movewise\Matching\Brief;
use Modules\Movewise\Matching\Connections;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\State;
use Modules\Movewise\Program\Agents;
use Modules\Movewise\Program\Packages;
use Modules\Movewise\Program\Pricing;

/**
 * Back office → Movewise (specs/movewise/03): an agent applies in a state, builds their profile card, signs the
 * program terms and sets up packages; the owner confirms their agents and can buy a broker package for the office.
 */
final class ProgramController
{
    public function __construct(private readonly Agents $agents, private readonly Packages $packages, private readonly TenantContext $tenants) {}

    public function show(Request $request): Response
    {
        $user = self::user($request);
        $tenantId = $this->tenants->id();
        $owner = $user->isKind(UserKind::Owner);
        $mine = Agent::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->orderBy('state')->get();
        $states = State::query()->orderBy('name')->get();
        $packages = Package::query()->where('tenant_id', $tenantId)->when(! $owner, fn ($q) => $q->where('payer_user_id', $user->id))->orderBy('id')->get();
        $team = $owner ? Agent::query()->where('tenant_id', $tenantId)->orderBy('state')->get() : collect();
        $names = $owner ? User::query()->whereIn('id', $team->pluck('user_id'))->get()->keyBy('id') : collect();

        return Inertia::render('movewise/program', [
            'is_owner' => $owner,
            'terms_version' => Agents::TERMS_VERSION,
            'specialties' => Agents::SPECIALTIES,
            'models' => Pricing::MODELS,
            'states' => $states->map(fn (State $s): array => ['code' => $s->code, 'name' => $s->name, 'on' => $s->on, 'models' => Pricing::offered($s), 'pricing' => Pricing::for($s)])->values()->all(),
            'mine' => $mine->map(fn (Agent $a): array => $this->agentRow($a))->values()->all(),
            'team' => $team->map(fn (Agent $a): array => $this->agentRow($a) + ['name' => $names[$a->user_id]->name ?? 'Agent'])->values()->all(),
            'introductions' => Connection::query()->where('tenant_id', $tenantId)->whereIn('agent_id', $mine->pluck('id'))->orderByDesc('id')->limit(50)->get()
                ->map(fn (Connection $c): array => ['ulid' => $c->ulid, 'status' => $c->status, 'lead_type' => $c->lead_type, 'offered_at' => $c->offered_at->toIso8601String(),
                    'accept_by' => $c->accept_by->toIso8601String(), 'credits' => $c->price_credits, 'refund_status' => $c->refund_status])->values()->all(),
            'packages' => $packages->map(fn (Package $p): array => ['ulid' => $p->ulid, 'name' => $p->name, 'state' => $p->state, 'broker' => $p->agent_id === null, 'status' => $p->status,
                'pause_reason' => $p->pause_reason, 'model' => $p->model, 'lead_types' => $p->lead_types, 'languages' => $p->languages, 'price_min' => $p->price_min, 'price_max' => $p->price_max,
                'zips' => implode(' ', $p->areas['zips']), 'cities' => implode(', ', $p->areas['cities']), 'monthly_cap' => $p->monthly_cap, 'daily_cap' => $p->daily_cap, 'hours' => $p->hours,
                'agents' => Agent::query()->whereIn('id', $p->agentIds())->pluck('ulid')->all(), 'missing' => $p->status === 'active' ? [] : $this->packages->missing($p),
                'balance' => $this->packages->balance($p)])->values()->all(),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $agent = $this->agents->apply(self::user($request), $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => $agent->status === 'active' ? 'Saved. You\'re live in the program.' : 'Saved.']);

        return back();
    }

    public function submit(Request $request, string $agent): RedirectResponse
    {
        $this->agents->submit($this->own($request, $agent));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to Movewise for review. We usually reply within two working days.']);

        return back();
    }

    public function terms(Request $request, string $agent): RedirectResponse
    {
        $this->agents->signTerms($this->own($request, $agent), $request->boolean('agree'), $request->ip());

        return back();
    }

    public function confirm(Request $request, string $agent): RedirectResponse
    {
        $a = Agent::query()->where('ulid', $agent)->where('tenant_id', $this->tenants->id())->firstOrFail();
        $this->agents->confirmBrokerage($a, self::user($request), $request->boolean('yes', true));

        return back();
    }

    public function savePackage(Request $request, ?string $package = null): RedirectResponse
    {
        $p = $package !== null ? Package::query()->where('ulid', $package)->where('tenant_id', $this->tenants->id())->firstOrFail() : null;
        $user = self::user($request);
        abort_if($p !== null && ! $user->isKind(UserKind::Owner) && $p->payer_user_id !== $user->id, 403);
        $this->packages->save($p, $user, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Package saved.']);

        return back();
    }

    public function activate(Request $request, string $package): RedirectResponse
    {
        $this->packages->activate(Package::query()->where('ulid', $package)->firstOrFail(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Live. You\'ll be among the agents people can choose.']);

        return back();
    }

    public function pause(Request $request, string $package): RedirectResponse
    {
        $this->packages->pause(Package::query()->where('ulid', $package)->firstOrFail(), self::user($request), 'Paused by you');

        return back();
    }

    /** One introduction: the brief (contact details once accepted), and accept, decline or report a problem. */
    public function introduction(Request $request, string $connection, Brief $brief): Response
    {
        $c = $this->mineConnection($request, $connection);

        return Inertia::render('movewise/introduction', ['ulid' => $c->ulid, 'status' => $c->status, 'accept_by' => $c->accept_by->toIso8601String(),
            'credits' => $c->price_credits, 'model' => $c->model, 'refund_status' => $c->refund_status, 'refund_days' => (int) config('movewise.refund_days', 7),
            'brief' => $brief->for($c, $c->status === 'accepted'), 'relay' => $c->handed_over_at === null ? $c->relay_number : null,
            'paused_until' => $c->paused_until?->isFuture() ? $c->paused_until->toIso8601String() : null, 'handover_asked' => $c->handover_asked_at !== null,
            'handed_over' => $c->handed_over_at !== null]);
    }

    public function decide(Request $request, string $connection, Connections $connections): RedirectResponse
    {
        $c = $this->mineConnection($request, $connection);
        $user = self::user($request);
        match ((string) $request->input('action')) {
            'accept' => $connections->accept($c, $user),
            'decline' => $connections->decline($c, $user),
            'report' => $connections->report($c, $user, (string) $request->input('reason', '')),
            'handover' => app(Relay::class)->askHandover($c, $user, $request->boolean('signed_or_closed')),
            default => abort(422),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => match ((string) $request->input('action')) {
            'accept' => 'Accepted. They are in your contacts now: reach out the way they asked.',
            'decline' => 'Declined. They will choose another agent; nothing was charged.',
            'handover' => 'Asked. If they reply YES, you can text them from your own number.',
            default => 'Sent to Movewise. We\'ll reply within two working days.',
        }]);

        return back();
    }

    private function mineConnection(Request $request, string $ulid): Connection
    {
        $user = self::user($request);
        $c = Connection::query()->where('ulid', $ulid)->where('tenant_id', $this->tenants->id())->firstOrFail();
        abort_unless(Agent::query()->whereKey($c->agent_id)->value('user_id') === $user->id, 404);

        return $c;
    }

    /** @return array<string, mixed> */
    private function agentRow(Agent $a): array
    {
        return ['ulid' => $a->ulid, 'state' => $a->state, 'licence_number' => $a->licence_number, 'licence_status' => $a->licence_status, 'brokerage_name' => $a->brokerage_name,
            'brokerage_confirmed' => $a->brokerage_confirmed_at !== null, 'profile' => $a->profile, 'profile_status' => $a->profile_status, 'review_note' => $a->review_note,
            'terms_signed' => $a->terms_signed_at !== null && $a->terms_version === Agents::TERMS_VERSION, 'status' => $a->status, 'tier' => $a->tier, 'seat' => $a->seat,
            'missing' => $this->agents->missing($a)];
    }

    private function own(Request $request, string $agent): Agent
    {
        return Agent::query()->where('ulid', $agent)->where('tenant_id', $this->tenants->id())->where('user_id', self::user($request)->id)->firstOrFail();
    }

    private static function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User && $u->isKind(UserKind::Owner, UserKind::Agent), 403);

        return $u;
    }
}
