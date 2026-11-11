<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

final class ActivityController
{
    public function __construct(private readonly ContactVisibility $visibility) {}

    public function store(Request $request, Contact $contact, Timeline $timeline): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(['note', 'call', 'meeting', 'email', 'text'])],
            'body' => ['nullable', 'string', 'max:10000'],
            'subject' => ['nullable', 'string', 'max:200'],
            'outcome' => ['nullable', Rule::in(Activity::CALL_OUTCOMES)],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $timeline->log($contact, $data, $user);

        return back();
    }

    public function pin(Request $request, Activity $activity, Timeline $timeline): RedirectResponse
    {
        abort_unless($this->visibility->canSee(self::user($request), $activity->contact), 404);
        $timeline->togglePin($activity);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
