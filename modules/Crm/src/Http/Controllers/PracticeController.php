<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Practice\PracticeClients;

/** SX5: practice mode — add or remove five made-up clients. */
final class PracticeController
{
    public function start(Request $request, PracticeClients $practice): RedirectResponse
    {
        $added = $practice->add(self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $added > 0
            ? 'Five made-up people were added to My people, each named "Practice". Try anything: nothing is sent to them, and only you can see them.'
            : 'Your practice people are already in My people.']);

        return redirect('/contacts?search=Practice');
    }

    public function clear(Request $request, PracticeClients $practice): RedirectResponse
    {
        $removed = $practice->remove(self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $removed > 0 ? 'Your practice people were removed.' : 'There were no practice people to remove.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
