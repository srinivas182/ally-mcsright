<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Listings\Idx\Coverage;
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
        ]);
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
