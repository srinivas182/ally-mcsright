<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Connect\Calendars;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Booking\Availability;

/** Settings → My availability: when the website may book calls, showings and listing appointments with you. */
final class AvailabilityController
{
    public function __construct(private readonly Availability $availability) {}

    public function show(Request $request): Response
    {
        $user = self::user($request);

        return Inertia::render('settings/availability', [
            'availability' => $this->availability->for($user->id),
            'timezone' => $this->availability->timezone(),
            'calendar' => app(Calendars::class)->connected($user->id),
            'next' => array_map(fn ($s): string => $s->setTimezone($this->availability->timezone())->format('D M j, g:i A'), $this->availability->slots($user, 'call', 6, 6)),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'hours' => ['present', 'array'], 'slot_minutes' => ['required', 'integer'],
            'notice_hours' => ['required', 'integer']]);
        $this->availability->save($user, (bool) $data['enabled'], $data['hours'], (int) $data['slot_minutes'], (int) $data['notice_hours'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Availability saved.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Agent, UserKind::Owner, UserKind::Staff], true), 403);

        return $user;
    }
}
