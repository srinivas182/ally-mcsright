<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Listings\Idx\Coverage;
use Modules\Listings\Idx\Subscriptions;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;

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
        ]);
    }

    public function move(Request $request, Subscriptions $subs, string $subscription): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:300']]);
        $subs->move($subscription, $data['to'], $data['note'] ?? null, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Moved to: '.(Subscriptions::STATUSES[$data['to']] ?? $data['to']).'.']);

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
