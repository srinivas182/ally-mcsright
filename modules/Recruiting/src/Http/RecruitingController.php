<?php

declare(strict_types=1);

namespace Modules\Recruiting\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Plan;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Recruiting\AgentDatabase;
use Modules\Recruiting\EarnHere;
use Modules\Recruiting\Models\Campaign;
use Modules\Recruiting\Models\Enrollment;
use Modules\Recruiting\Models\MlsAgent;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Pipeline;

/** Recruiting (RC1, specs/grow/17): the pipeline, the MLS agent database, and each recruit with "what you'd earn here". */
final class RecruitingController
{
    public function index(Request $request, Pipeline $pipeline): Response
    {
        self::user($request);
        $recruits = Recruit::query()->with('mlsAgent')->where(fn ($q) => $q->whereIn('stage', Recruit::OPEN)
            ->orWhere('stage_changed_on', '>=', now()->subDays(90)->toDateString()))->orderBy('next_step_on')->orderBy('name')->get();
        $owners = User::query()->whereIn('id', $recruits->pluck('owner_user_id')->filter()->unique()->all())->get()->keyBy('id');

        return Inertia::render('recruiting/index', [
            'stages' => collect(Recruit::STAGES)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'recruits' => $recruits->map(fn (Recruit $r): array => self::card($r, $owners->get((int) $r->owner_user_id)?->first_name))->values()->all(),
            'alerts' => $pipeline->alerts(),
            'due' => $pipeline->dueCount(),
        ]);
    }

    public function store(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $recruit = $pipeline->add($request->only(['name', 'email', 'phone', 'brokerage', 'source', 'next_step', 'next_step_on']), self::user($request));

        return to_route('recruiting.show', $recruit);
    }

    public function agents(Request $request, AgentDatabase $db): Response
    {
        $user = self::user($request);
        $filters = $request->only(['q', 'min_sides', 'moved', 'sort']);
        $page = $db->search($filters);
        $recruited = Recruit::query()->whereIn('mls_agent_id', collect($page->items())->pluck('id')->all() ?: [0])->pluck('ulid', 'mls_agent_id');
        $owner = $user->isKind(UserKind::Owner);

        return Inertia::render('recruiting/agents', [
            'licensed' => $db->sources() !== [],
            'filters' => $filters,
            'agents' => $page->through(fn (MlsAgent $a): array => ['id' => $a->id, 'name' => $a->displayName(), 'mls_id' => $a->agent_mls_id, 'office' => $a->office_name,
                'sides' => $a->sides, 'list_sides' => $a->list_sides, 'buyer_sides' => $a->buyer_sides, 'volume_cents' => $a->volume_cents, 'trend' => $a->trend(),
                'sides_before' => $a->sides_before, 'active_listings' => $a->active_listings, 'moved_on' => $a->office_changed_on?->toDateString(),
                'previous_office' => $a->previous_office_name, 'recruit' => $recruited->get($a->id), 'move_score' => $a->move_score, 'move_reasons' => $a->move_reasons ?? []]),
            'settings' => $owner ? $db->settings() + ['plans' => Plan::query()->where('type', PlanType::Commission)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Plan $p): array => ['id' => $p->id, 'name' => $p->name])->all()] : null,
        ]);
    }

    public function recruitAgent(Request $request, int $agent, Pipeline $pipeline): RedirectResponse
    {
        $recruit = $pipeline->addFromMls(MlsAgent::query()->findOrFail($agent), self::user($request));

        return to_route('recruiting.show', $recruit);
    }

    public function settings(Request $request, AgentDatabase $db): RedirectResponse
    {
        abort_unless(self::user($request)->isKind(UserKind::Owner), 403);
        $db->saveSettings($request->only(['own_office_ids', 'commission_rate', 'compare_plan_id']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Recruiting settings saved.']);

        return back();
    }

    public function show(Request $request, Recruit $recruit, Pipeline $pipeline, EarnHere $earn): Response
    {
        $viewer = self::user($request);
        $pipeline->seen($recruit);
        $manual = $request->validate(['deals' => ['nullable', 'integer', 'min:1', 'max:100'], 'price' => ['nullable', 'numeric', 'min:1000', 'max:100000000']]);
        $a = $recruit->mlsAgent;
        $users = User::query()->whereIn('id', $recruit->notes()->pluck('user_id')->filter()->unique()->all())->get()->keyBy('id');

        return Inertia::render('recruiting/show', [
            'recruit' => self::card($recruit, null) + ['email' => $recruit->email, 'phone' => $recruit->phone, 'owner_user_id' => $recruit->owner_user_id,
                'current_split' => $recruit->current_split !== null ? (float) $recruit->current_split : null,
                'current_cap' => $recruit->current_cap_cents !== null ? intdiv($recruit->current_cap_cents, 100) : null,
                'current_fee' => $recruit->current_fee_cents !== null ? intdiv($recruit->current_fee_cents, 100) : null, 'not_now_reason' => $recruit->not_now_reason],
            'production' => $a !== null ? ['sides' => $a->sides, 'list_sides' => $a->list_sides, 'buyer_sides' => $a->buyer_sides, 'volume_cents' => $a->volume_cents,
                'sides_before' => $a->sides_before, 'trend' => $a->trend(), 'active_listings' => $a->active_listings, 'office' => $a->office_name,
                'last_closed_on' => $a->last_closed_on?->toDateString(), 'moved_on' => $a->office_changed_on?->toDateString(), 'previous_office' => $a->previous_office_name,
                'move_score' => $a->move_score, 'move_reasons' => $a->move_reasons ?? []] : null,
            'joining' => ['can_invite' => $viewer->can('core.users.invite') && $recruit->email !== null && $recruit->user_id === null && in_array($recruit->stage, ['meeting', 'offer', 'joined'], true),
                'invited_on' => $recruit->invited_at?->toDateString(), 'joined_on' => $recruit->joined_on?->toDateString(),
                'sponsor' => $recruit->sponsor_user_id !== null ? User::query()->find($recruit->sponsor_user_id)?->name : null],
            'reach' => ['email_opt_out_on' => $recruit->email_opt_out_at?->toDateString(), 'text_consent_on' => $recruit->text_consent_at?->toDateString(),
                'text_opt_out_on' => $recruit->text_opt_out_at?->toDateString(), 'application' => $recruit->application],
            'campaigns' => Campaign::query()->where('status', 'active')->orderBy('name')->get(['ulid', 'name'])->map(fn (Campaign $c): array => ['ulid' => $c->ulid, 'name' => $c->name])->all(),
            'enrollments' => Enrollment::query()->with('campaign')->where('recruit_id', $recruit->id)->orderByDesc('id')->get()->map(fn (Enrollment $e): array => [
                'campaign' => $e->campaign->ulid, 'name' => $e->campaign->name, 'status' => $e->status, 'step' => min($e->next_step + 1, count($e->campaign->steps)),
                'steps' => count($e->campaign->steps), 'next_at' => $e->next_at?->toDateString(), 'reason' => $e->stopped_reason])->all(),
            'notes' => $recruit->notes()->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()->map(fn ($n): array => ['kind' => $n->kind, 'body' => $n->body,
                'at' => $n->occurred_at->toDateTimeString(), 'by' => $n->user_id !== null ? $users->get($n->user_id)?->name : 'RightAlly'])->all(),
            'stages' => collect(Recruit::STAGES)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'recruiters' => User::query()->whereIn('kind', [UserKind::Owner, UserKind::Staff])->where('status', 'active')->orderBy('first_name')->get()
                ->filter(fn (User $u): bool => $u->can('recruiting.manage'))->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            'earn' => $earn->compare($recruit, ['deals' => $manual['deals'] ?? null, 'price_cents' => isset($manual['price']) ? (int) round((float) $manual['price'] * 100) : null]),
            'manual' => ['deals' => $manual['deals'] ?? null, 'price' => $manual['price'] ?? null],
        ]);
    }

    public function update(Request $request, Recruit $recruit, Pipeline $pipeline): RedirectResponse
    {
        self::user($request);
        $pipeline->update($recruit, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    public function stage(Request $request, Recruit $recruit, Pipeline $pipeline): RedirectResponse
    {
        $d = $request->validate(['stage' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:200']]);
        $pipeline->move($recruit, (string) $d['stage'], self::user($request), $d['reason'] ?? null);

        return back();
    }

    public function note(Request $request, Recruit $recruit, Pipeline $pipeline): RedirectResponse
    {
        $pipeline->note($recruit, $request->only(['kind', 'body', 'next_step', 'next_step_on']), self::user($request));

        return back();
    }

    public function plan(Request $request, Recruit $recruit): RedirectResponse
    {
        self::user($request);
        $id = $request->validate(['plan_id' => ['nullable', 'integer']])['plan_id'] ?? null;
        $plan = $id !== null ? Plan::query()->where('type', PlanType::Commission)->find((int) $id) : null;
        $recruit->fill(['compare_plan_id' => $plan?->id])->save();

        return back();
    }

    /** @return array<string, mixed> */
    private static function card(Recruit $r, ?string $owner): array
    {
        $a = $r->mlsAgent;

        return ['ulid' => $r->ulid, 'name' => $r->name, 'brokerage' => $r->brokerage, 'stage' => $r->stage, 'source' => $r->source, 'owner' => $owner,
            'next_step' => $r->next_step, 'next_step_on' => $r->next_step_on?->toDateString(), 'overdue' => $r->next_step_on !== null && $r->next_step_on->isBefore(today()),
            'sides' => $a?->sides, 'volume_cents' => $a?->volume_cents, 'trend' => $a?->trend(), 'move_score' => $a?->move_score];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('recruiting.manage'), 403);

        return $user;
    }
}
