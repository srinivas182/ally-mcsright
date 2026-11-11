<?php

declare(strict_types=1);

namespace Modules\Crm\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Events\ActivityLogged;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/**
 * The contact timeline: notes, calls, meetings and logged emails/texts, plus automatic system
 * entries (contact added, stage changed, owner changed). Keeps the contact's last activity current.
 */
final class Timeline
{
    /**
     * @param  array{type: string, body?: string|null, subject?: string|null, outcome?: string|null, direction?: string|null, duration_minutes?: int|null, occurred_at?: string|null}  $data
     */
    public function log(Contact $contact, array $data, User $by): Activity
    {
        $type = $data['type'];
        if (! in_array($type, ['note', 'call', 'meeting', 'email', 'text'], true)) {
            throw ValidationException::withMessages(['type' => 'Choose note, call, meeting, email or text.']);
        }
        if ($type === 'note' && trim((string) ($data['body'] ?? '')) === '') {
            throw ValidationException::withMessages(['body' => 'Write the note.']);
        }
        if ($type === 'call' && ! in_array($data['outcome'] ?? null, Activity::CALL_OUTCOMES, true)) {
            throw ValidationException::withMessages(['outcome' => 'How did the call go?']);
        }

        $occurred = isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : CarbonImmutable::now();
        if ($occurred->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'Log things that already happened; use a task for the future.']);
        }

        $activity = Activity::query()->create([
            'contact_id' => $contact->id,
            'type' => $type,
            'direction' => in_array($data['direction'] ?? null, ['in', 'out'], true) ? $data['direction'] : ($type === 'note' ? null : 'out'),
            'outcome' => $type === 'call' ? $data['outcome'] : null,
            'duration_seconds' => isset($data['duration_minutes']) ? max(0, (int) $data['duration_minutes']) * 60 : null,
            'subject' => isset($data['subject']) ? mb_substr(trim((string) $data['subject']), 0, 200) : null,
            'body' => isset($data['body']) ? trim((string) $data['body']) : null,
            'user_id' => $by->id,
            'occurred_at' => $occurred,
        ]);

        if ($contact->last_activity_at === null || $occurred->gt($contact->last_activity_at)) {
            $contact->newQuery()->whereKey($contact->id)->update(['last_activity_at' => $occurred]);
        }

        ActivityLogged::dispatch($contact->tenant_id, $contact->id, $activity->id, $type);

        return $activity;
    }

    public function system(Contact $contact, string $text, ?int $byUserId = null): Activity
    {
        return Activity::query()->create([
            'contact_id' => $contact->id, 'type' => 'system', 'body' => $text, 'user_id' => $byUserId, 'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    public function togglePin(Activity $activity): Activity
    {
        if ($activity->type === 'system') {
            throw ValidationException::withMessages(['activity' => 'Automatic entries cannot be pinned.']);
        }
        $activity->update(['pinned' => ! $activity->pinned]);

        return $activity;
    }
}
