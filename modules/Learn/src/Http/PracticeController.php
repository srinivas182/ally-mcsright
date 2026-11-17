<?php

declare(strict_types=1);

namespace Modules\Learn\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Learn\Library;
use Modules\Learn\Models\Article;
use Modules\Learn\Models\RolePlaySession;
use Modules\Learn\RolePlay;

/** The role-play academy (LN3): practice, the daily drill, rehearsals, coaching cards, and the broker's view of effort. */
final class PracticeController
{
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $today = RolePlay::todays($user);
        $history = RolePlaySession::query()->where('user_id', $user->id)->orderByDesc('id')->limit(30)->get();
        $team = [];
        if (Library::manages($user)) {
            $since = CarbonImmutable::now()->subDays(30);
            $rows = RolePlaySession::query()->where('created_at', '>=', $since)->whereNotNull('finished_at')->get()->groupBy('user_id');
            $names = User::query()->whereIn('id', $rows->keys())->get()->mapWithKeys(fn (User $u): array => [$u->id => (string) $u->name]);
            foreach ($rows as $id => $list) {
                $scored = $list->whereNotNull('score');
                $team[] = ['name' => (string) ($names[$id] ?? ''), 'sessions' => $list->count(), 'average' => $scored->isEmpty() ? null : (int) round((float) $scored->avg('score'))];
            }
            usort($team, fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
        }

        return Inertia::render('learn/practice', [
            'scenarios' => array_map(fn (string $k, array $s): array => ['key' => $k, 'title' => $s[0], 'skill' => $s[1], 'goal' => $s[4]], array_keys(RolePlay::SCENARIOS), RolePlay::SCENARIOS),
            'today' => ['key' => $today, 'title' => RolePlay::SCENARIOS[$today][0], 'done' => RolePlaySession::query()->where('user_id', $user->id)->where('kind', 'daily')
                ->whereNotNull('finished_at')->where('finished_at', '>=', CarbonImmutable::today())->exists()],
            'streak' => RolePlay::streak($user),
            'history' => $history->map(fn (RolePlaySession $s): array => ['ulid' => $s->ulid, 'kind' => $s->kind,
                'title' => $s->scenario !== null ? RolePlay::SCENARIOS[$s->scenario][0] : match ($s->kind) {
                    'rehearsal' => 'Appointment rehearsal', 'call' => 'Call coaching', default => 'Deal debrief'
                },
                'score' => $s->score, 'finished' => $s->finished_at !== null, 'at' => $s->created_at->toDateString()])->values(),
            'team' => $team,
        ]);
    }

    public function start(Request $request, RolePlay $rp): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:practice,daily,rehearsal'], 'scenario' => ['nullable', 'string', 'max:40'], 'brief' => ['nullable', 'string', 'max:1000']]);
        $s = $rp->start(self::user($request), $data['kind'], $data['scenario'] ?? null, $data['brief'] ?? null);

        return redirect('/learn/practice/'.$s->ulid);
    }

    public function show(Request $request, string $session): Response
    {
        $s = self::mine($request, $session);

        return Inertia::render('learn/roleplay', ['session' => ['ulid' => $s->ulid, 'kind' => $s->kind,
            'title' => $s->scenario !== null ? RolePlay::SCENARIOS[$s->scenario][0] : ($s->kind === 'rehearsal' ? 'Appointment rehearsal' : ($s->kind === 'call' ? 'Call coaching' : 'Deal debrief')),
            'goal' => $s->scenario !== null ? RolePlay::SCENARIOS[$s->scenario][4] : $s->brief, 'transcript' => (array) $s->transcript, 'turns' => $s->turns,
            'max_turns' => RolePlay::MAX_TURNS, 'score' => $s->score, 'feedback' => $s->feedback, 'finished' => $s->finished_at !== null]]);
    }

    public function reply(Request $request, string $session, RolePlay $rp): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:1500']]);
        $rp->reply(self::mine($request, $session), $data['text'], self::user($request));

        return back();
    }

    public function finish(Request $request, string $session, RolePlay $rp): RedirectResponse
    {
        $rp->finish(self::mine($request, $session), self::user($request));

        return back();
    }

    public function coach(Request $request, RolePlay $rp): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:call,debrief'], 'text' => ['required', 'string', 'max:8000']]);
        $s = $rp->coach(self::user($request), $data['kind'], $data['text']);

        return redirect('/learn/practice/'.$s->ulid);
    }

    /** Offers the lesson from a deal debrief to the office playbook, as a draft page the broker reviews. */
    public function suggest(Request $request, string $session): RedirectResponse
    {
        $s = self::mine($request, $session);
        $user = self::user($request);
        $f = (array) $s->feedback;
        abort_unless($s->kind === 'debrief' && ($f['lesson'] ?? '') !== '', 404);
        $body = "**Lesson:** {$f['lesson']}\n\n**What worked:**\n".implode("\n", array_map(fn ($l): string => '- '.$l, (array) ($f['went_well'] ?? [])))
            ."\n\n_Suggested by {$user->name} from a deal debrief. Review before publishing._";
        $a = Article::query()->create(['title' => mb_substr('Playbook: '.$f['lesson'], 0, 160), 'category' => 'Playbooks', 'body' => $body, 'required' => false,
            'version' => 1, 'status' => 'draft', 'updated_by' => $user->id]);
        app(AuditLogger::class)->record('learn.playbook.suggested', metadata: ['article' => $a->ulid]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to your broker as a draft playbook page. Thank you!']);

        return back();
    }

    private static function mine(Request $request, string $ulid): RolePlaySession
    {
        $s = RolePlaySession::query()->where('ulid', $ulid)->first();
        abort_if($s === null || $s->user_id !== self::user($request)->id, 404); // practice is private, even from the broker

        return $s;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], true), 403);

        return $user;
    }
}
