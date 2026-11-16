<?php

declare(strict_types=1);

namespace Modules\Core\Notices;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;

/**
 * The notification centre (specs/core/10, N1): the bell, the inbox page and each person's preferences.
 */
final class NoticesController
{
    public function __construct(private readonly Notices $notices, private readonly NoticeTypes $types) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $seen = DB::connection(Connections::cell())->table('user_notices')->where('user_id', $user->id)->distinct()->pluck('type')->map(fn ($t): string => (string) $t)->all();
        $kinds = [];
        foreach (array_unique([...array_keys($this->types->all()), ...$seen]) as $key) {
            $kinds[] = ['type' => $key, 'label' => $this->types->label($key), 'group' => $this->types->all()[$key]['group'] ?? 'General',
                'urgent' => $this->types->all()[$key]['urgent'] ?? false] + $this->notices->pref($user, $key);
        }
        usort($kinds, fn (array $a, array $b): int => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);

        return Inertia::render('notifications/index', [
            'notices' => $this->notices->latest($user, 100),
            'kinds' => $kinds,
            'settings' => $this->notices->settings($user),
            'noisy' => $this->notices->noisy($user),
            'timezone' => $user->timezone ?: 'America/Chicago',
        ]);
    }

    public function latest(Request $request): JsonResponse
    {
        $user = self::user($request);

        return response()->json(['notices' => $this->notices->latest($user, 8), 'unread' => $this->notices->unread($user)]);
    }

    public function read(Request $request, ?string $notice = null): RedirectResponse|JsonResponse
    {
        $user = self::user($request);
        $this->notices->markRead($user, $notice);

        return $request->wantsJson() ? response()->json(['unread' => $this->notices->unread($user)]) : back();
    }

    public function snooze(Request $request, string $notice): RedirectResponse
    {
        $data = $request->validate(['hours' => ['required', 'integer', 'in:3,24,168']]);
        $this->notices->snooze(self::user($request), $notice, (int) $data['hours']);

        return back();
    }

    public function preference(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['type' => ['required', 'string', 'max:80'], 'in_app' => ['required', 'boolean'], 'email' => ['required', 'boolean']]);
        $this->notices->setPref($user, $data['type'], (bool) $data['in_app'], (bool) $data['email']);

        return back();
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate(['quiet_start' => ['nullable', 'integer', 'between:0,23'], 'quiet_end' => ['nullable', 'integer', 'between:0,23'], 'digest' => ['required', 'boolean']]);
        $this->notices->saveSettings(self::user($request), isset($data['quiet_start']) ? (int) $data['quiet_start'] : null,
            isset($data['quiet_end']) ? (int) $data['quiet_end'] : null, (bool) $data['digest']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
