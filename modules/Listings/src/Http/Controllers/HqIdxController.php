<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Listings\Idx\Agreements;
use Modules\Listings\Idx\BoardRequests;
use Modules\Listings\Idx\Coverage;
use Modules\Listings\Idx\Subscriptions;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** IDX1: HQ › IDX coverage: open states, the MLS board directory and the data connectors. */
final class HqIdxController
{
    public function index(Request $request, Coverage $coverage): Response
    {
        $state = strtoupper((string) $request->query('state', ''));

        return Inertia::render('hq/idx', [
            'states' => $coverage->states(),
            'boards' => $coverage->boards(),
            'connectors' => $coverage->connectors(),
            'profiles' => RulesProfile::query()->orderBy('name')->get(['id', 'name'])->map(fn (RulesProfile $p): array => ['id' => $p->id, 'name' => $p->name]),
            'sources' => MlsSource::query()->orderBy('name')->get(['id', 'name'])->map(fn (MlsSource $s): array => ['id' => $s->id, 'name' => $s->name]),
            'state' => preg_match('/^[A-Z]{2}$/', $state) === 1 ? $state : null,
            'queue' => app(Subscriptions::class)->queue(),
            'statuses' => Subscriptions::STATUSES,
            'requests' => app(BoardRequests::class)->queue(), // IDX8
        ]);
    }

    public function agreementPdf(Request $request, Coverage $coverage, string $board): RedirectResponse
    {
        $file = $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:20480']])['file'];
        $coverage->saveAgreementPdf($board, (string) file_get_contents($file->getRealPath()), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Agreement saved. A signature page is added to it for each brokerage.']);

        return back();
    }

    public function sendNow(Request $request, Agreements $agreements, string $subscription): RedirectResponse
    {
        $sent = $agreements->deliver($subscription, true, self::user($request));
        Inertia::flash('toast', $sent ? ['type' => 'success', 'message' => 'Signed agreement sent to the MLS.']
            : ['type' => 'error', 'message' => 'Nothing to send: the agreement is not signed yet, or the board has no "send to" address.']);

        return back();
    }

    public function copy(string $subscription): StreamedResponse
    {
        $path = DB::connection(Connections::control())->table('idx_subscriptions')->where('ulid', $subscription)->value('signed_path');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'IDX agreement.pdf');
    }

    public function move(Request $request, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:300']]);
        $subs->move($subscription, $data['to'], $data['note'] ?? null, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Moved to: '.(Subscriptions::STATUSES[$data['to']] ?? $data['to']).'.']);

        return back();
    }

    /** IDX8: add a board a brokerage asked for (hidden until it has a connector and a fee). */
    public function addRequested(Request $request, BoardRequests $requests, string $boardRequest): RedirectResponse
    {
        $requests->add($boardRequest, (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added to MLS boards as hidden. Give it a connector and a fee, then make it public: the brokerage is emailed then.']);

        return back();
    }

    public function closeRequested(Request $request, BoardRequests $requests, string $boardRequest): RedirectResponse
    {
        $requests->close($boardRequest, (string) $request->validate(['note' => ['required', 'string', 'max:500']])['note'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Closed. The brokerage has been told why.']);

        return back();
    }

    public function override(Request $request, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $subs->override($subscription, (string) $request->validate(['connector' => ['required', 'string', 'max:30']])['connector'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connector changed for this brokerage only.']);

        return back();
    }

    public function live(Request $request, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $data = $request->validate(['source_id' => ['nullable', 'integer'], 'feed_id' => ['nullable', 'string', 'max:120']]);
        $subs->goLive($subscription, isset($data['source_id']) ? (int) $data['source_id'] : null, $data['feed_id'] ?? null, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Live. The brokerage has been told.']);

        return back();
    }

    public function cancel(Request $request, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $subs->cancel($subscription, null, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cancelled. Its listings are hidden from the brokerage\'s website.']);

        return back();
    }

    public function state(Request $request, Coverage $coverage, string $code): RedirectResponse
    {
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $coverage->setState($code, $on, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $on ? 'State opened: its public boards show at sign-up.' : 'State closed: it no longer shows at sign-up.']);

        return back();
    }

    public function saveBoard(Request $request, Coverage $coverage, ?string $board = null): RedirectResponse
    {
        $coverage->saveBoard($board, $request->all(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Board saved.']);

        return back();
    }

    public function saveConnector(Request $request, Coverage $coverage, string $connector): RedirectResponse
    {
        $coverage->saveConnector($connector, $request->all(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connector saved.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
