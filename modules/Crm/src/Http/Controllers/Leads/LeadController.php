<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Leads\VisitorJourneys;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Enums\Source;
use Modules\Crm\Leads\AiCaller;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\Attribution;
use Modules\Crm\Leads\LeadReports;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;
use Modules\Crm\Models\RoutingRule;

/** Leads screens (specs/grow/04): incoming leads with speed figures, and the sources and routing settings. */
final class LeadController
{
    public function index(Request $request, SpeedToLead $speed, ContactVisibility $visibility, VisitorJourneys $journeys): Response
    {
        $user = self::user($request);
        $manager = CrmAccess::manager($user);
        $mine = static fn ($q) => $q->where('assigned_user_id', $user->id)->orWhere('responded_by', $user->id);
        $leads = Lead::query()->with('contact')->where('status', '!=', 'needs_review')
            ->when(! $manager, fn ($q) => $q->where(fn ($q) => $mine($q)->orWhere('status', 'offered')))
            ->latest('id')->limit(150)->get()
            ->filter(fn (Lead $l): bool => $manager || $l->status !== 'offered' || in_array($user->id, $l->offered_to ?? [], true))->take(100)->values();
        $names = User::query()->whereIn('id', $leads->pluck('assigned_user_id')->filter()->unique())->get()->keyBy('id');
        $visible = $leads->filter(fn (Lead $l): bool => $l->contact !== null && $visibility->canSee($user, $l->contact));
        $journey = $journeys->summaries(array_values(array_unique($visible->pluck('contact_id')->map(fn ($id): int => (int) $id)->all())));

        $since = CarbonImmutable::now()->subDays(30);
        $recent = Lead::query()->where('created_at', '>=', $since)->where('status', '!=', 'needs_review')
            ->when(! $manager, fn ($q) => $q->where(fn ($q) => $mine($q)))->get(['channel', 'response_seconds', 'responded_at']);

        return Inertia::render('crm/leads/index', [
            'leads' => $leads->map(fn (Lead $l): array => [
                'ulid' => $l->ulid, 'received' => $l->created_at->toIso8601String(), 'name' => $l->contact !== null ? $l->contact->name : 'Unknown',
                'contact' => $l->contact !== null && $visibility->canSee($user, $l->contact) ? $l->contact->ulid : null,
                'source' => SpeedToLead::sourceLabel($l->source), 'channel' => $l->channel, 'summary' => SpeedToLead::summary($l),
                'message' => $l->contact !== null && $visibility->canSee($user, $l->contact) ? $l->message : null, 'status' => $l->status,
                'agent' => $l->assigned_user_id !== null ? $names->get($l->assigned_user_id)?->name : null,
                'response_minutes' => $l->response_seconds !== null ? round($l->response_seconds / 60, 1) : null, 'new_contact' => $l->new_contact,
                'can_claim' => $l->status === 'offered' && in_array($user->id, $l->offered_to ?? [], true),
                'score' => $l->score, 'score_label' => $l->score_label, 'score_reasons' => $l->score_reasons ?? [],
                'journey' => $l->contact !== null && $visibility->canSee($user, $l->contact) ? ($journey[$l->contact->id] ?? null) : null,
            ]),
            'review' => $manager ? Lead::query()->where('status', 'needs_review')->latest('id')->limit(20)->get()
                ->map(fn (Lead $l): array => ['ulid' => $l->ulid, 'received' => $l->created_at->toIso8601String(), 'source' => SpeedToLead::sourceLabel($l->source), 'raw' => mb_substr((string) $l->raw, 0, 3000)]) : [],
            'stats' => self::stats($recent),
            'alerts' => $speed->prefs($user),
            'canManage' => $manager,
            'focus' => $request->string('lead')->toString() ?: null,
        ]);
    }

    /** What the lead did on the brokerage's websites, newest first, with the summary (specs/websites/07). */
    public function journey(Request $request, Lead $lead, ContactVisibility $visibility, VisitorJourneys $journeys): JsonResponse
    {
        $user = self::user($request);
        $contact = $lead->contact;
        abort_unless($contact !== null && $visibility->canSee($user, $contact), 404);

        return response()->json(['journey' => $journeys->forContact($contact->id)]);
    }

    public function report(Request $request, LeadReports $reports): Response
    {
        abort_unless(CrmAccess::manager(self::user($request)), 403);
        $days = in_array((int) $request->query('days', 90), [30, 90, 180, 365], true) ? (int) $request->query('days', 90) : 90;
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);

        return Inertia::render('crm/leads/report', [
            'days' => $days,
            'returns' => $reports->returns($from, $to),
            'scorecard' => $reports->scorecard($from, $to),
            'sourceOptions' => array_map(fn (Source $s): array => ['value' => $s->value, 'label' => SpeedToLead::sourceLabel($s->value)], Source::cases()),
            'channels' => Attribution::CHANNELS,
            'month' => $to->format('Y-m'),
        ]);
    }

    public function saveSpend(Request $request, LeadReports $reports): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'string'], 'key' => ['required', 'string', 'max:40'], 'amount' => ['required', 'numeric', 'min:0', 'max:100000000']]);
        $reports->saveSpend((string) $data['month'], (string) $data['key'], (int) round((float) $data['amount'] * 100), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Spend saved.']);

        return back();
    }

    public function saveTargets(Request $request, LeadReports $reports): RedirectResponse
    {
        $data = $request->validate(['target_minutes' => ['required', 'integer'], 'target_percent' => ['required', 'integer']]);
        $reports->saveTargets((int) $data['target_minutes'], (int) $data['target_percent'], self::user($request));

        return back();
    }

    public function claim(Request $request, Lead $lead, LeadRouting $routing): RedirectResponse
    {
        $routing->claim($lead, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The lead is yours. Reach out now!']);

        return back();
    }

    public function alerts(Request $request, SpeedToLead $speed): RedirectResponse
    {
        $data = $request->validate(['texts' => ['required', 'boolean'], 'calls' => ['required', 'boolean'], 'away_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'copy_all' => ['nullable', 'boolean']]);
        $speed->savePrefs(self::user($request), (bool) $data['texts'], (bool) $data['calls'], isset($data['away_hours']) ? (int) $data['away_hours'] : null,
            isset($data['copy_all']) ? (bool) $data['copy_all'] : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lead alerts saved.']);

        return back();
    }

    public function settings(Request $request, LeadRouting $routing, SpeedToLead $speed): Response
    {
        abort_unless(CrmAccess::manager(self::user($request)), 403);

        return Inertia::render('crm/leads/settings', [
            'sources' => LeadSource::query()->orderBy('name')->get()->map(fn (LeadSource $s): array => [
                'ulid' => $s->ulid, 'name' => $s->name, 'kind' => $s->kind, 'source' => $s->source->value, 'active' => $s->active,
                'last_lead_at' => $s->last_lead_at?->toIso8601String(), 'key_last4' => $s->key_last4,
                'address' => $s->kind === 'email' ? LeadSources::address($s) : null,
                'form_url' => $s->kind === 'form' ? $speed->link('/l/'.$s->token) : null,
                'embed' => $s->kind === 'form' ? LeadSources::embed($s, $speed->link('/l/'.$s->token)) : null,
                'api_url' => $s->kind === 'api' ? $speed->link('/api/leads') : null,
            ]),
            'rules' => RoutingRule::query()->orderBy('position')->orderBy('id')->get()->map(fn (RoutingRule $r): array => [
                'ulid' => $r->ulid, 'name' => $r->name, 'method' => $r->method, 'agent_ids' => $r->agent_ids, 'sources' => $r->sources ?? [], 'zips' => $r->zips ?? [],
                'price_min' => $r->price_min, 'price_max' => $r->price_max, 'intent' => $r->intent, 'active' => $r->active,
            ]),
            'settings' => $routing->settings(),
            'agents' => User::query()->where('status', 'active')->whereIn('kind', [UserKind::Agent, UserKind::Owner, UserKind::Staff])->orderBy('first_name')->get()
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name]),
            'sourceOptions' => array_map(fn (Source $s): array => ['value' => $s->value, 'label' => SpeedToLead::sourceLabel($s->value)], Source::cases()),
            'channels' => Attribution::CHANNELS,
            'newKey' => $request->session()->get('lead_key'),
            'ai' => app(AiResponder::class)->settings(),
            'aiCalls' => app(AiCaller::class)->month(),
        ]);
    }

    public function saveSettings(Request $request, LeadRouting $routing): RedirectResponse
    {
        $routing->saveSettings($request->only(['respond_minutes', 'max_passes', 'fallback', 'fallback_user_id']), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lead settings saved.']);

        return back();
    }

    public function saveAi(Request $request, AiResponder $ai): RedirectResponse
    {
        $ai->saveSettings($request->only(['enabled', 'nurture_enabled', 'assistant_name', 'tone', 'max_messages', 'sources', 'knowledge', 'voice_enabled', 'voice', 'voice_language', 'brand_name', 'voice_monthly_cap']), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI assistant saved.']);

        return back();
    }

    public function storeSource(Request $request, LeadSources $sources): RedirectResponse
    {
        $made = $sources->create($request->only(['name', 'kind', 'source', 'heading', 'consent_wording']), self::user($request));
        if ($made['key'] !== null) {
            $request->session()->flash('lead_key', ['source' => $made['source']->ulid, 'key' => $made['key']]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lead source added.']);

        return back();
    }

    public function sourceActive(Request $request, LeadSource $source, LeadSources $sources): RedirectResponse
    {
        $sources->setActive($source, $request->boolean('active'), self::user($request));

        return back();
    }

    public function rotateKey(Request $request, LeadSource $source, LeadSources $sources): RedirectResponse
    {
        $key = $sources->rotateKey($source, self::user($request));
        $request->session()->flash('lead_key', ['source' => $source->ulid, 'key' => $key]);

        return back();
    }

    public function storeRule(Request $request, LeadRouting $routing): RedirectResponse
    {
        $routing->saveRule(self::ruleInput($request), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Routing rule saved.']);

        return back();
    }

    public function updateRule(Request $request, RoutingRule $rule, LeadRouting $routing): RedirectResponse
    {
        $routing->saveRule(self::ruleInput($request), self::user($request), $rule);

        return back();
    }

    public function destroyRule(Request $request, RoutingRule $rule): RedirectResponse
    {
        abort_unless(CrmAccess::manager(self::user($request)), 403);
        $rule->delete();

        return back();
    }

    public function orderRules(Request $request, LeadRouting $routing): RedirectResponse
    {
        $data = $request->validate(['order' => ['required', 'array', 'max:100'], 'order.*' => ['string', 'max:26']]);
        $routing->reorder(array_values($data['order']), self::user($request));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private static function ruleInput(Request $request): array
    {
        return $request->only(['name', 'method', 'agent_ids', 'sources', 'zips', 'price_min', 'price_max', 'intent', 'active']);
    }

    /**
     * @param  Collection<int, Lead>  $recent
     * @return array{total: int, by_channel: array<string, int>, median_minutes: float|null, within_5: int|null}
     */
    private static function stats(Collection $recent): array
    {
        $times = $recent->pluck('response_seconds')->filter(fn ($v): bool => $v !== null)->sort()->values();
        $median = null;
        if ($times->isNotEmpty()) {
            $mid = intdiv($times->count(), 2);
            $median = round(($times->count() % 2 === 1 ? (int) $times[$mid] : ((int) $times[$mid - 1] + (int) $times[$mid]) / 2) / 60, 1);
        }
        $byChannel = array_fill_keys(Attribution::CHANNELS, 0);
        foreach ($recent as $l) {
            $byChannel[$l->channel] = ($byChannel[$l->channel] ?? 0) + 1;
        }

        return ['total' => $recent->count(), 'by_channel' => $byChannel, 'median_minutes' => $median,
            'within_5' => $recent->isNotEmpty() ? (int) round(100 * $times->filter(fn ($s): bool => $s <= 300)->count() / $recent->count()) : null];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
