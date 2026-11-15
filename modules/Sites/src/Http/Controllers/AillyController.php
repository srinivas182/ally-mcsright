<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Credits\CreditSpend;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Ailly\AillySettings;
use Modules\Sites\Ailly\Assistant;
use Modules\Sites\Ailly\BrokerageAnswers;
use Modules\Sites\Ailly\Experiments;
use Modules\Sites\Ailly\LiveChat;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\SiteContext;
use Modules\Sites\Visitors\SiteLeads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AIlly (specs/websites/11): the website chat endpoints (JSON, no cookies) and Settings → AIlly for owners and staff.
 */
final class AillyController
{
    public function __construct(private readonly Assistant $ailly) {}

    public function start(Request $request): JsonResponse
    {
        $site = self::site($request);
        if ($site === null || ! RateLimiter::attempt('ailly-start:'.$request->ip(), 10, fn (): bool => true)) {
            return response()->json(['ok' => false], 429);
        }
        try {
            return response()->json(['ok' => true] + $this->ailly->start($site, $request));
        } catch (ValidationException) {
            return response()->json(['ok' => false]);
        }
    }

    public function message(Request $request): JsonResponse
    {
        $site = self::site($request);
        $data = $request->validate(['conversation' => ['required', 'string', 'size:26'], 'message' => ['required', 'string', 'max:800']]);
        if ($site === null || ! RateLimiter::attempt('ailly-msg:'.$request->ip(), 20, fn (): bool => true)) {
            return response()->json(['ok' => false, 'reply' => 'Too many messages at once. Please wait a moment.'], 429);
        }

        return response()->json(['ok' => true] + $this->ailly->reply($site, $data['conversation'], $data['message'], $request));
    }

    public function contact(Request $request, SiteLeads $leads): JsonResponse
    {
        $site = self::site($request);
        $data = $request->validate(['conversation' => ['required', 'string', 'size:26'], 'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:190'], 'phone' => ['nullable', 'required_without:email', 'string', 'max:30'],
            'sms_consent' => ['nullable', 'boolean'], 'want' => ['nullable', 'in:call,showing,listing'], 'when' => ['nullable', 'string', 'max:120'],
            'slot' => ['nullable', 'string', 'max:40'], 'slot_agent' => ['nullable', 'required_with:slot', 'string', 'size:26'],
            'listing' => ['nullable', 'string', 'size:26'], 'intent' => ['nullable', 'in:buy,sell,rent,invest']]);
        if ($site === null || Assistant::isBot($request) || ! RateLimiter::attempt('ailly-contact:'.$request->ip(), 6, fn (): bool => true)) {
            return response()->json(['ok' => false], 429);
        }
        try {
            $done = $this->ailly->capture($site, $data['conversation'], $data, $request, $leads);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'error' => (string) collect($e->errors())->flatten()->first(), 'slots' => $this->ailly->slots($site, (string) ($data['want'] ?? 'call'))], 422);
        }

        return response()->json(['ok' => true] + $done);
    }

    /** New messages from an agent who joined the chat (the widget asks every few seconds while it is open). */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate(['conversation' => ['required', 'string', 'size:26'], 'after' => ['nullable', 'integer', 'min:0']]);
        if (! RateLimiter::attempt('ailly-poll:'.$request->ip(), 40, fn (): bool => true)) {
            return response()->json(['ok' => false, 'messages' => []], 429);
        }
        try {
            return response()->json(['ok' => true] + $this->ailly->poll($data['conversation'], (int) ($data['after'] ?? 0)));
        } catch (ValidationException) {
            return response()->json(['ok' => false, 'messages' => []]);
        }
    }

    /** Open times to book a call, showing or listing appointment on this website. */
    public function slots(Request $request): JsonResponse
    {
        $site = self::site($request);
        $kind = (string) ($request->validate(['kind' => ['nullable', 'in:call,showing,listing']])['kind'] ?? 'call');
        if ($site === null || ! RateLimiter::attempt('ailly-slots:'.$request->ip(), 30, fn (): bool => true)) {
            return response()->json(['ok' => false, 'slots' => []], 429);
        }

        return response()->json(['ok' => true, 'slots' => $this->ailly->slots($site, $kind)]);
    }

    /**
     * Settings → AIlly assistant: results (chats, leads, calls, credits, cost per lead and per booked call, by page and
     * by website), the chats with filters, the brokerage's answers and questions AIlly couldn't answer, and settings.
     */
    public function settings(Request $request, TenantContext $tenants, MediaLibrary $media, BrokerageAnswers $answers, CreditSpend $spend): Response
    {
        self::manager($request);
        $f = $request->validate(['days' => ['nullable', 'in:7,30,90'], 'outcome' => ['nullable', 'in:all,none,lead,call,showing,listing'], 'score' => ['nullable', 'integer', 'min:0', 'max:100']]);
        $days = (int) ($f['days'] ?? 30);
        $from = CarbonImmutable::now()->subDays($days)->startOfDay();
        $site = Site::query()->where('kind', 'brokerage')->first();
        $db = fn () => DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $tenants->id())->where('created_at', '>=', $from);

        $chats = $db()->count();
        $leads = $db()->where('outcome', '!=', 'none')->count();
        $calls = $db()->where('outcome', 'call')->count();
        $showings = $db()->where('outcome', 'showing')->count();
        $listings = $db()->where('outcome', 'listing')->count();
        $cost = $spend->spent('ai.ailly', $from, CarbonImmutable::now()->addMinute());
        $brief = $spend->spent('ai.closing_brief', $from, CarbonImmutable::now()->addMinute());
        $total = $cost['microcents'] + $brief['microcents'];
        $rate = fn (int $n, int $of): int => $of > 0 ? (int) round($n * 100 / $of) : 0;

        $byPage = $db()->selectRaw("coalesce(page, '/') as page, count(*) as chats, sum(case when outcome != 'none' then 1 else 0 end) as leads")
            ->groupBy('page')->orderByDesc('chats')->limit(10)->get();
        $bySite = $db()->selectRaw("site_id, count(*) as chats, sum(case when outcome != 'none' then 1 else 0 end) as leads, sum(case when outcome = 'call' then 1 else 0 end) as calls")
            ->groupBy('site_id')->get();
        $siteNames = Site::query()->whereIn('id', $bySite->pluck('site_id'))->get()->mapWithKeys(fn (Site $s): array => [$s->id => $s->kind === 'brokerage' ? 'Brokerage website' : (string) ($s->info['name'] ?? 'Agent website')]);

        $list = $db()->when(($f['outcome'] ?? 'all') !== 'all', fn ($q) => $q->where('outcome', $f['outcome']))
            ->when(isset($f['score']), fn ($q) => $q->where('lead_score', '>=', (int) $f['score']))->orderByDesc('id')->limit(50)->get();
        $messages = DB::connection(Connections::cell())->table('ailly_messages')->whereIn('conversation_id', $list->pluck('id'))->orderBy('id')->get()->groupBy('conversation_id');
        $flagged = DB::connection(Connections::cell())->table('ailly_messages')->where('tenant_id', $tenants->id())->where('created_at', '>=', $from)->whereNotNull('flags')->count();

        return Inertia::render('settings/ailly', [
            'site' => $site !== null ? ['live' => $site->status === 'live', 'name' => $site->displayName()] : null,
            'settings' => AillySettings::for($site),
            'photo_url' => $site !== null ? AillySettings::persona($site, $site)['photo'] : null,
            'site_ulid' => $site?->ulid,
            'media' => $site !== null ? PageEditorController::mediaList($site, $media) : [],
            'filters' => ['days' => $days, 'outcome' => $f['outcome'] ?? 'all', 'score' => isset($f['score']) ? (int) $f['score'] : null],
            'results' => ['chats' => $chats, 'leads' => $leads, 'calls' => $calls, 'showings' => $showings, 'listings' => $listings, 'lead_rate' => $rate($leads, $chats),
                'answers' => (int) $db()->sum('ai_replies'), 'free_answers' => (int) $db()->sum('cached_replies'), 'credits' => $cost['credits'] + $brief['credits'],
                'spent_cents' => (int) round($total / 1_000_000), 'cost_per_lead_cents' => $leads > 0 ? (int) round($total / 1_000_000 / $leads) : null,
                'cost_per_call_cents' => $calls > 0 ? (int) round($total / 1_000_000 / $calls) : null, 'flagged' => $flagged],
            'by_page' => $byPage->map(fn (object $r): array => ['page' => (string) $r->page, 'chats' => (int) $r->chats, 'leads' => (int) $r->leads, 'rate' => $rate((int) $r->leads, (int) $r->chats)])->values(),
            'by_site' => $bySite->map(fn (object $r): array => ['site' => (string) ($siteNames[$r->site_id] ?? 'Website'), 'chats' => (int) $r->chats, 'leads' => (int) $r->leads,
                'calls' => (int) $r->calls, 'rate' => $rate((int) $r->leads, (int) $r->chats)])->sortByDesc('chats')->values(),
            'conversations' => $list->map(fn (object $c): array => ['ulid' => (string) $c->ulid, 'at' => (string) $c->created_at, 'page' => (string) ($c->page ?? ''),
                'outcome' => (string) $c->outcome, 'score' => $c->lead_score !== null ? (int) $c->lead_score : null, 'answers' => (int) $c->ai_replies,
                'messages' => array_values(array_map(fn (\stdClass $m): array => ['role' => (string) $m->role, 'body' => (string) $m->body, 'flagged' => $m->flags !== null],
                    array_filter(($messages->get($c->id) ?? collect())->all(), fn ($m): bool => $m instanceof \stdClass)))])->values(),
            'answers' => $answers->list(),
            'open_questions' => $answers->openQuestions(),
            'test' => Experiments::for($site),
            'current_greeting' => AillySettings::greeting(AillySettings::for($site), AillySettings::for($site)['name']),
            'test_results' => app(Experiments::class)->results($site),
            'live' => app(LiveChat::class)->live(self::manager($request)),
            'team_options' => User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->orderBy('first_name')->get()->filter(fn (User $u): bool => $u->isActive())
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    public function saveAnswer(Request $request, BrokerageAnswers $answers): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['id' => ['nullable', 'integer'], 'question' => ['required', 'string', 'max:200'], 'answer' => ['required', 'string', 'max:1200']]);
        $answers->save(isset($data['id']) ? (int) $data['id'] : null, $data['question'], $data['answer'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Answer saved. AIlly uses it from now on.']);

        return back();
    }

    public function deleteAnswer(Request $request, BrokerageAnswers $answers, int $answer): RedirectResponse
    {
        self::manager($request);
        $answers->delete($answer);

        return back();
    }

    public function dismissQuestion(Request $request, BrokerageAnswers $answers, int $question): RedirectResponse
    {
        self::manager($request);
        $answers->dismiss($question);

        return back();
    }

    /**
     * The Fair Housing audit for a month (CSV): every AIlly answer the content guard changed, with the visitor's
     * question, the answer sent and what was removed. Formula-safe.
     */
    public function audit(Request $request, TenantContext $tenants): StreamedResponse
    {
        self::manager($request);
        $month = (string) ($request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['month'] ?? CarbonImmutable::now()->format('Y-m'));
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')?->startOfDay() ?? CarbonImmutable::now()->startOfMonth();
        $rows = DB::connection(Connections::cell())->table('ailly_messages')->where('tenant_id', $tenants->id())->where('role', 'ailly')
            ->where('created_at', '>=', $start)->where('created_at', '<', $start->addMonth())->orderBy('id')->limit(50_000)->get();
        $convs = DB::connection(Connections::cell())->table('ailly_conversations')->whereIn('id', $rows->pluck('conversation_id')->unique())->pluck('ulid', 'id');
        $cell = fn (string $v): string => $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
        app(AuditLogger::class)->record('sites.ailly.audit_exported', metadata: ['month' => $month, 'answers' => $rows->count()], severity: 'notice');

        return response()->streamDownload(function () use ($rows, $convs, $cell): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Date (UTC)', 'Chat', 'Answered from', 'Answer sent', 'Removed by the Fair Housing and figure check'], escape: '');
            foreach ($rows as $m) {
                $flags = $m->flags !== null ? (array) json_decode((string) $m->flags, true) : [];
                fputcsv($out, [(string) $m->created_at, (string) ($convs[$m->conversation_id] ?? ''), (string) ($m->answered_from ?? 'ai'), $cell((string) $m->body),
                    $cell(implode(' | ', array_map('strval', $flags)))], escape: '');
            }
            fclose($out);
        }, 'ailly-audit-'.$month.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function save(Request $request): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'name' => ['required', 'string', 'max:30'], 'photo' => ['nullable', 'string', 'size:26'],
            'greeting' => ['nullable', 'string', 'max:240'],
            'gate' => ['required', 'in:before,after,booking'], 'gate_after' => ['required', 'integer', 'min:1', 'max:10'],
            'visitor_daily' => ['required', 'integer', 'min:5', 'max:100'], 'daily_cap' => ['required', 'integer', 'min:10', 'max:10000'],
            'team' => ['nullable', 'array', 'max:200'], 'team.*' => ['integer'], 'nudge' => ['nullable', 'boolean']]);
        $site = Site::query()->where('kind', 'brokerage')->first() ?? throw ValidationException::withMessages(['enabled' => 'Set up your website first.']);
        AillySettings::save($site, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['enabled'] ? 'AIlly is on your website.' : 'AIlly is off.']);

        return back();
    }

    /**
     * The website the chat is on: an agent's own domain (set by the middleware), an agent's pages at /agents/{slug}
     * on the brokerage's address (the widget sends the public slug), or the brokerage's.
     */
    /** Starts an A/B test of a second greeting and/or a different moment to ask for details. */
    public function startTest(Request $request, Experiments $experiments): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['greeting_b' => ['nullable', 'string', 'max:240'], 'gate_b' => ['nullable', 'in:before,after,booking']]);
        $site = Site::query()->where('kind', 'brokerage')->first() ?? throw ValidationException::withMessages(['greeting_b' => 'Set up your website first.']);
        $experiments->start($site, (string) ($data['greeting_b'] ?? ''), $data['gate_b'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Test started: half of new chats see version B.']);

        return back();
    }

    public function stopTest(Request $request, Experiments $experiments): RedirectResponse
    {
        $user = self::manager($request);
        $useB = (bool) $request->validate(['use_b' => ['required', 'boolean']])['use_b'];
        $site = Site::query()->where('kind', 'brokerage')->firstOrFail();
        $experiments->stop($site, $useB, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $useB ? 'Version B is now what every visitor sees.' : 'Test ended. Visitors see your settings.']);

        return back();
    }

    private static function site(Request $request): ?Site
    {
        $context = app(SiteContext::class)->agent();
        if ($context !== null) {
            return $context;
        }
        $slug = $request->input('agent_site');
        if (is_string($slug) && preg_match('/^[a-z0-9-]{1,80}$/', $slug) === 1) {
            $agent = Site::query()->where('kind', 'agent')->where('slug', $slug)->where('status', 'live')->first();
            if ($agent !== null) {
                app(SiteContext::class)->enterAgent($agent, '/agents/'.$agent->slug);

                return $agent;
            }
        }

        return Assistant::brokerageSite();
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff], true), 403);

        return $user;
    }
}
