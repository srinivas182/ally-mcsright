<?php

declare(strict_types=1);

namespace Modules\Roster\Http;

use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Roster\Agreements;
use Modules\Roster\Compliance;
use Modules\Roster\ContinuingEducation;
use Modules\Roster\Flows;
use Modules\Roster\LicenceGuard;
use Modules\Roster\Roster;
use Modules\Roster\UpdateRequests;
use Modules\Roster\Verification;
use Modules\Transactions\Models\Deal;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RosterController
{
    public function index(Request $request, Compliance $compliance, Roster $roster): Response
    {
        $this->manager($request);

        $agreements = app(Agreements::class);

        return Inertia::render('roster/index', ['dashboard' => $compliance->dashboard(), 'settings' => $roster->settings(),
            'isOwner' => self::user($request)->isKind(UserKind::Owner), 'requests' => app(UpdateRequests::class)->pending(),
            'agreements' => ['agreement' => $agreements->latest('agreement'), 'policy' => $agreements->latest('policy')],
            'ceRules' => DB::connection(Connections::cell())->table('roster_ce_rules')->orderBy('state_code')->get()
                ->map(fn ($r): array => ['state' => $r->state_code, 'hours' => (string) BigDecimal::of((string) $r->hours_required)->toScale(1), 'cycle_months' => (int) $r->cycle_months, 'topics' => json_decode((string) $r->topics, true)])]);
    }

    /** An agent's records: managers see anyone; an agent sees their own (read-only). */
    public function show(Request $request, string $agent, Roster $roster): Response
    {
        $user = self::user($request);
        $target = $agent === 'me' ? $user : User::query()->where('ulid', $agent)->firstOrFail();
        abort_unless(Roster::manages($user) || $target->id === $user->id, 403);

        $flows = app(Flows::class);
        $agreements = app(Agreements::class);

        return Inertia::render('roster/agent', ['agent' => ['ulid' => $target->ulid, 'name' => $target->name], 'records' => $roster->forUser($target),
            'canManage' => Roster::manages($user), 'isSelf' => $target->id === $user->id, 'isOwner' => $user->isKind(UserKind::Owner),
            'onboarding' => $flows->view($target, 'onboarding'), 'offboarding' => $flows->view($target, 'offboarding'),
            'toSign' => $target->id === $user->id ? array_values(array_filter([$agreements->latest('agreement'), $agreements->latest('policy')],
                fn ($a) => $a !== null && ! $agreements->signed($target, $a['kind']))) : [],
            'requests' => app(UpdateRequests::class)->pending($target->id),
            'ce' => app(ContinuingEducation::class)->progress($target),
            'canVerify' => Roster::manages($user) && app(Verification::class)->available()]);
    }

    public function add(Request $request, string $agent, Roster $roster): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:licence,eo,association,mls'], 'state_code' => ['nullable', 'string', 'size:2'], 'number' => ['nullable', 'string', 'max:40'],
            'issuer' => ['nullable', 'string', 'max:120'], 'coverage' => ['nullable', 'numeric', 'min:0'], 'expires_on' => ['nullable', 'date']]);
        $roster->add(User::query()->where('ulid', $agent)->firstOrFail(), $data['kind'], ['state_code' => $data['state_code'] ?? null, 'number' => $data['number'] ?? null,
            'issuer' => $data['issuer'] ?? null, 'coverage_cents' => isset($data['coverage']) ? (int) round((float) $data['coverage'] * 100) : null,
            'expires_on' => $data['expires_on'] ?? null], $this->manager($request));

        return back();
    }

    public function verify(Request $request, string $credential, Roster $roster): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300'], 'evidence' => ['nullable', 'string', 'size:26']]);
        $roster->verify($credential, $data['note'], $data['evidence'] ?? null, $this->manager($request));

        return back();
    }

    public function upload(Request $request, string $agent, Roster $roster): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'string'], 'file' => ['required', 'file', 'max:15360']]);
        $roster->upload(User::query()->where('ulid', $agent)->firstOrFail(), $data['kind'], $request->file('file'), $this->manager($request));

        return back();
    }

    public function download(Request $request, string $document): StreamedResponse
    {
        $user = self::user($request);
        $doc = DB::connection(Connections::cell())->table('roster_documents')->where('ulid', $document)->first();
        abort_if($doc === null, 404);
        abort_unless(Roster::manages($user) || (int) $doc->user_id === $user->id, 403);
        app(AuditLogger::class)->record('roster.document.downloaded', metadata: ['document' => $document, 'kind' => $doc->kind]);

        return Storage::disk('local')->download((string) $doc->path, (string) $doc->filename);
    }

    public function settings(Request $request, Roster $roster): RedirectResponse
    {
        $data = $request->validate(['guard_licence' => ['required', 'boolean'], 'eo_required' => ['required', 'boolean']]);
        $roster->saveSettings((bool) $data['guard_licence'], (bool) $data['eo_required'], self::user($request));

        return back();
    }

    public function override(Request $request, Deal $deal, LicenceGuard $guard): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $guard->override($deal, $data['reason'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'This deal is allowed past the licence guard.']);

        return back();
    }

    private function manager(Request $request): User
    {
        $user = self::user($request);
        abort_unless(Roster::manages($user), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function publishAgreement(Request $request, Agreements $agreements): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:agreement,policy'], 'title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:200000']]);
        $agreements->publish($data['kind'], $data['title'], $data['body'], self::user($request));

        return back();
    }

    public function sign(Request $request, string $kind, Agreements $agreements): RedirectResponse
    {
        $data = $request->validate(['typed_name' => ['required', 'string', 'max:120'], 'agree' => ['accepted']]);
        $agreements->sign(self::user($request), $kind, $data['typed_name'], true, $request->ip(), $request->userAgent());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Signed. A copy is in your documents.']);

        return back();
    }

    public function startFlow(Request $request, string $agent, Flows $flows): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:onboarding,offboarding'], 'leaving_on' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:200']]);
        $flows->start(User::query()->where('ulid', $agent)->firstOrFail(), $data['kind'], self::user($request),
            isset($data['leaving_on']) ? CarbonImmutable::parse($data['leaving_on']) : null, $data['reason'] ?? null);

        return back();
    }

    public function markTask(Request $request, int $task, Flows $flows): RedirectResponse
    {
        $data = $request->validate(['done' => ['required', 'boolean']]);
        $flows->mark($task, (bool) $data['done'], self::user($request));

        return back();
    }

    public function completeFlow(Request $request, string $agent, string $kind, Flows $flows): RedirectResponse
    {
        abort_unless(in_array($kind, ['onboarding', 'offboarding'], true), 404);
        $flows->complete(User::query()->where('ulid', $agent)->firstOrFail(), $kind, $this->manager($request));

        return back();
    }

    public function submitRequest(Request $request, UpdateRequests $requests): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:licence,eo,association,mls'], 'state_code' => ['nullable', 'string', 'size:2'], 'number' => ['nullable', 'string', 'max:40'],
            'issuer' => ['nullable', 'string', 'max:120'], 'expires_on' => ['nullable', 'date'], 'file' => ['required', 'file', 'max:15360']]);
        $requests->submit(self::user($request), $data['kind'], ['state_code' => $data['state_code'] ?? null, 'number' => $data['number'] ?? null,
            'issuer' => $data['issuer'] ?? null, 'expires_on' => $data['expires_on'] ?? null], $request->file('file'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to your brokerage for approval.']);

        return back();
    }

    public function decideRequest(Request $request, string $update, UpdateRequests $requests): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:300']]);
        $requests->decide($update, (bool) $data['approve'], $data['note'] ?? null, $this->manager($request));

        return back();
    }

    public function ceRule(Request $request, ContinuingEducation $ce): RedirectResponse
    {
        $data = $request->validate(['state' => ['required', 'string', 'size:2'], 'hours' => ['required', 'numeric', 'min:0.5'], 'cycle_months' => ['required', 'integer'],
            'topics' => ['array', 'max:10'], 'topics.*.topic' => ['nullable', 'string', 'max:60'], 'topics.*.hours' => ['nullable', 'numeric']]);
        $ce->saveRule($data['state'], (string) $data['hours'], (int) $data['cycle_months'], array_values(array_map(fn ($t) => ['topic' => (string) ($t['topic'] ?? ''), 'hours' => (string) ($t['hours'] ?? '0')],
            $data['topics'] ?? [])), self::user($request));

        return back();
    }

    public function ceLog(Request $request, string $agent, ContinuingEducation $ce): RedirectResponse
    {
        $user = self::user($request);
        $target = $agent === 'me' ? $user : User::query()->where('ulid', $agent)->firstOrFail();
        $data = $request->validate(['state' => ['required', 'string', 'size:2'], 'title' => ['required', 'string', 'max:160'], 'provider' => ['nullable', 'string', 'max:120'],
            'topic' => ['nullable', 'string', 'max:60'], 'hours' => ['required', 'numeric', 'min:0.5', 'max:100'], 'completed_on' => ['required', 'date'], 'file' => ['nullable', 'file', 'max:15360']]);
        $ce->log($target, $data['state'], $data['title'], $data['provider'] ?? null, $data['topic'] ?? null, (string) $data['hours'],
            CarbonImmutable::parse($data['completed_on']), $request->file('file'), $user);

        return back();
    }

    public function ceRemove(Request $request, string $course, ContinuingEducation $ce): RedirectResponse
    {
        $ce->remove($course, self::user($request));

        return back();
    }

    public function check(Request $request, string $credential, Verification $verification): RedirectResponse
    {
        $result = $verification->check($credential, $this->manager($request));
        Inertia::flash('toast', ['type' => $result === 'active' ? 'success' : 'error', 'message' => $result === 'active' ? 'Active per state records.' : 'State records disagree — owners have been told.']);

        return back();
    }

    /** CSV of the roster for the brokerage (audited). */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->manager($request);
        $db = DB::connection(Connections::cell());
        $rows = ['name,email,phone,status,licences,eo_insurer,eo_expires,memberships,onboarding'];
        foreach (User::query()->where('kind', UserKind::Agent)->orderBy('first_name')->get() as $a) {
            $creds = $db->table('roster_credentials')->where('user_id', $a->id)->where('status', 'current')->get();
            $eo = $creds->firstWhere('kind', 'eo');
            $q = fn (string $v): string => '"'.str_replace('"', '""', $v).'"';
            $rows[] = implode(',', [$q($a->name), $q($a->email), $q((string) $a->phone), $a->status->value,
                $q($creds->where('kind', 'licence')->map(fn ($c) => "{$c->state_code} {$c->number} (exp {$c->expires_on})")->implode('; ')),
                $q((string) ($eo->issuer ?? '')), (string) ($eo->expires_on ?? ''),
                $q($creds->whereIn('kind', ['association', 'mls'])->map(fn ($c) => trim("{$c->issuer} {$c->number}"))->implode('; ')),
                (string) ($db->table('roster_flows')->where('user_id', $a->id)->where('kind', 'onboarding')->orderByDesc('id')->value('status') ?? '')]);
        }
        app(AuditLogger::class)->record('roster.exported', metadata: ['agents' => count($rows) - 1], severity: 'notice');

        return response(implode("\n", $rows)."\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="roster.csv"']);
    }

    /** Internal directory: everyone in the brokerage can find a colleague. */
    public function directory(Request $request): Response
    {
        self::user($request);
        $db = DB::connection(Connections::cell());
        $q = trim((string) $request->query('q', ''));
        $people = User::query()->where('status', UserStatus::Active)->whereIn('kind', [UserKind::Owner, UserKind::Staff, UserKind::Agent])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('first_name')->limit(300)->get();

        return Inertia::render('roster/directory', ['q' => $q, 'people' => $people->map(fn (User $u): array => [
            'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'role' => $u->kind->value,
            'offices' => $db->table('user_offices')->join('offices', 'offices.id', '=', 'user_offices.office_id')->where('user_offices.user_id', $u->id)->pluck('offices.name')->all(),
            'teams' => $db->table('team_members')->join('teams', 'teams.id', '=', 'team_members.team_id')->where('team_members.user_id', $u->id)->pluck('teams.name')->all(),
            'licences' => $db->table('roster_credentials')->where('user_id', $u->id)->where('kind', 'licence')->where('status', 'current')
                ->get(['state_code', 'number'])->map(fn ($c) => "{$c->state_code} {$c->number}")->all(),
        ])->values()]);
    }
}
