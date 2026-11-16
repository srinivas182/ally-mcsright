<?php

declare(strict_types=1);

namespace Modules\Core\Simple;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;

/** The simple home and the simple/full switch (SM1). */
final class SimpleController
{
    public function home(Request $request, DailyBriefs $briefs): Response
    {
        $user = self::user($request);
        $for = $user->assistedAgent ?? $user;

        return Inertia::render('simple/home', ['firstName' => $user->first_name, 'today' => array_slice($briefs->for($for), 0, 5)]);
    }

    public function set(Request $request, SimpleMode $simple): RedirectResponse
    {
        $user = self::user($request);
        $d = $request->validate(['mode' => ['nullable', 'in:simple,full'], 'locale' => ['nullable', 'in:en,es']]);
        $simple->set($user, $d['mode'] ?? null, $d['locale'] ?? null);

        return ($d['mode'] ?? null) === 'simple' ? redirect('/simple') : (($d['mode'] ?? null) === 'full' ? redirect($user->isKind(UserKind::Agent) || $user->isKind(UserKind::Assistant) ? '/agent' : '/dashboard') : back());
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
