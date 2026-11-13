<?php

declare(strict_types=1);

namespace Modules\Roster\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Roster\Compliance;
use Modules\Roster\LicenceGuard;
use Modules\Roster\Roster;
use Modules\Transactions\Models\Deal;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RosterController
{
    public function index(Request $request, Compliance $compliance, Roster $roster): Response
    {
        $this->manager($request);

        return Inertia::render('roster/index', ['dashboard' => $compliance->dashboard(), 'settings' => $roster->settings(),
            'isOwner' => self::user($request)->isKind(UserKind::Owner)]);
    }

    /** An agent's records: managers see anyone; an agent sees their own (read-only). */
    public function show(Request $request, string $agent, Roster $roster): Response
    {
        $user = self::user($request);
        $target = $agent === 'me' ? $user : User::query()->where('ulid', $agent)->firstOrFail();
        abort_unless(Roster::manages($user) || $target->id === $user->id, 403);

        return Inertia::render('roster/agent', ['agent' => ['ulid' => $target->ulid, 'name' => $target->name], 'records' => $roster->forUser($target),
            'canManage' => Roster::manages($user)]);
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
}
