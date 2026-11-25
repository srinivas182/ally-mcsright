<?php

declare(strict_types=1);

namespace Modules\Roster\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Roster\MlsIds;
use Modules\Roster\Roster;

/** ML1: agents confirm (or type) their MLS ID; the owner checks the ones the MLS name did not match. */
final class MlsIdsController
{
    public function index(Request $request, MlsIds $ids): Response
    {
        abort_unless(Roster::manages(self::user($request)), 403);

        return Inertia::render('roster/mls-ids', ['overview' => $ids->overview(), 'boards' => $ids->boards()]);
    }

    /** The owner asks for a fresh look through the MLS (it also runs every night). */
    public function scan(Request $request, MlsIds $ids): RedirectResponse
    {
        abort_unless(Roster::manages(self::user($request)), 403);
        $found = $ids->scan();
        Inertia::flash('toast', ['type' => 'success', 'message' => $found === 0 ? 'Checked the MLS. Nothing new.' : "Checked the MLS. Found {$found} new MLS ID(s)."]);

        return back();
    }

    public function decide(Request $request, string $match, MlsIds $ids): RedirectResponse
    {
        $data = $request->validate(['mine' => ['required', 'boolean']]);
        $ids->decide(self::user($request), $match, (bool) $data['mine']);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['mine']
            ? 'Saved. Your MLS listings will now come into RightAlly.' : 'Thanks. Your broker will check who this ID belongs to.']);

        return back();
    }

    public function typed(Request $request, MlsIds $ids): RedirectResponse
    {
        $data = $request->validate(['board' => ['required', 'integer'], 'mls_id' => ['required', 'string', 'max:30']]);
        $result = $ids->typed(self::user($request), (int) $data['board'], (string) $data['mls_id']);
        Inertia::flash('toast', ['type' => $result === 'confirm' ? 'success' : 'info', 'message' => $result === 'confirm'
            ? 'Please check the listings we found and confirm.' : 'The listings under this ID show another name, so your broker will check it.']);

        return back();
    }

    public function assign(Request $request, string $match, MlsIds $ids): RedirectResponse
    {
        $data = $request->validate(['agent' => ['nullable', 'string', 'size:26']]);
        $agent = isset($data['agent']) ? User::query()->where('ulid', $data['agent'])->firstOrFail() : null;
        $ids->assign($match, $agent, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $agent !== null ? "Saved for {$agent->name}." : 'Marked as not someone in your brokerage.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
