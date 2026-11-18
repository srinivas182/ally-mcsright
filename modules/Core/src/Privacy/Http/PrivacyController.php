<?php

declare(strict_types=1);

namespace Modules\Core\Privacy\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Privacy\PrivacyRequests;
use Modules\Core\Privacy\Retention;
use stdClass;

/** Settings → Privacy (TR1, specs/core/18): privacy requests from the public, and how long data is kept. */
final class PrivacyController
{
    public function index(Request $request, PrivacyRequests $requests, Retention $retention): Response
    {
        $user = $this->handler($request, $requests);
        $list = $requests->list();
        $open = array_slice(array_filter($list, fn (stdClass $r): bool => $r->status === 'open'), 0, 20);
        $matches = [];
        foreach ($open as $r) {
            $matches[(string) $r->ulid] = array_map(fn (array $m): array => ['source' => $m['label'], 'record' => $m['record'], 'held' => $m['held']], $requests->matches($r));
        }

        return Inertia::render('settings/privacy', [
            'requests' => array_map(fn (stdClass $r): array => ['ulid' => (string) $r->ulid, 'kind' => (string) $r->kind, 'kind_label' => PrivacyRequests::KINDS[(string) $r->kind] ?? $r->kind,
                'name' => (string) $r->name, 'email' => (string) $r->email, 'phone' => $r->phone, 'details' => $r->details, 'status' => (string) $r->status,
                'due_on' => $r->due_on, 'late' => $r->status === 'open' && $r->due_on !== null && CarbonImmutable::parse((string) $r->due_on)->isPast(),
                'outcome' => $r->outcome, 'confirmed_at' => $r->confirmed_at, 'matches' => $matches[(string) $r->ulid] ?? null], $list),
            'retention' => $retention->rules(),
            'canSetRetention' => $user->kind === UserKind::Owner,
            'days' => PrivacyRequests::DAYS,
        ]);
    }

    public function complete(Request $request, PrivacyRequests $requests, string $ulid): RedirectResponse
    {
        $user = $this->handler($request, $requests);
        $outcome = $requests->complete($ulid, $user, $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Done. We emailed the person: '.$outcome]);

        return back();
    }

    public function decline(Request $request, PrivacyRequests $requests, string $ulid): RedirectResponse
    {
        $user = $this->handler($request, $requests);
        $requests->decline($ulid, $user, (string) $request->validate(['note' => ['required', 'string', 'max:500']])['note']);

        return back();
    }

    public function retention(Request $request, Retention $retention): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Owner, 403);
        $data = $request->validate(['kind' => ['required', 'string', 'max:40'], 'months' => ['nullable', 'integer', 'min:1', 'max:120']]);
        $retention->set($data['kind'], isset($data['months']) ? (int) $data['months'] : null, $user);

        return back();
    }

    /** The person's copy, by the signed link we emailed (valid 7 days). Built fresh from today's records. */
    public function copy(Request $request, PrivacyRequests $requests, string $request_ulid): HttpResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $req = $requests->find($request_ulid);
        abort_if($req === null || $req->kind !== 'know' || $req->status !== 'done', 404);

        return response((string) json_encode($requests->copy($req), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="my-information.json"',
            'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    private function handler(Request $request, PrivacyRequests $requests): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $requests->canHandle($user), 403);

        return $user;
    }
}
