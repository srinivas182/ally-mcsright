<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\DatabaseIntelligence;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Intent;
use Modules\Crm\TodaysTen;
use Throwable;

final class TodayController
{
    public function show(Request $request, TodaysTen $today): Response
    {
        return Inertia::render('crm/today', ['picks' => $today->for(self::user($request))]);
    }

    public function analyse(Request $request, Contact $contact, DatabaseIntelligence $intelligence, ContactVisibility $visibility): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($visibility->canSee($user, $contact), 404);

        try {
            $found = $intelligence->analyseNow($contact, $user);
            Inertia::flash('toast', ['type' => 'success', 'message' => count($found) === 0 ? 'No new plans found in the notes.' : count($found).' plan(s) found in the notes.']);
        } catch (Throwable) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'The AI assistant is unavailable right now. Please try again.']);
        }

        return back();
    }

    public function intent(Request $request, int $intent, ContactVisibility $visibility, DatabaseIntelligence $intelligence): RedirectResponse
    {
        $user = self::user($request);
        $model = Intent::query()->with('contact')->findOrFail($intent);
        abort_unless($visibility->canSee($user, $model->contact), 404);
        $data = $request->validate(['status' => ['required', 'in:done,dismissed,open']]);

        $model->update(['status' => $data['status']]);
        $intelligence->score($model->contact);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
