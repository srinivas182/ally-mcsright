<?php

declare(strict_types=1);

namespace Modules\Crm\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Events\TaskCompleted;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactBrief;
use Modules\Crm\Models\Task;

/**
 * Tasks and reminders. A task about a contact follows the contact's visibility; a task without a
 * contact is visible only to its assignee. Keeps the contact's next task date current.
 */
final class Tasks
{
    public function __construct(
        private readonly ContactVisibility $visibility,
        private readonly Timeline $timeline,
    ) {}

    /**
     * @param  array{title: string, type?: string|null, due?: string|null, due_at?: string|null, remind?: bool|null, notes?: string|null, priority?: string|null, assigned_to?: int|null}  $data
     */
    public function create(?Contact $contact, array $data, User $by): Task
    {
        $title = trim($data['title']);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'What needs doing?']);
        }

        $due = $this->dueAt($data['due'] ?? null, $data['due_at'] ?? null, $by);
        $assignee = $data['assigned_to'] ?? null;
        if ($assignee === null || ! $by->isKind(UserKind::Owner, UserKind::Staff)) {
            $assignee = $by->kind === UserKind::Assistant && $by->assisted_user_id !== null ? $by->assisted_user_id : $by->id;
        }

        $task = Task::query()->create([
            'contact_id' => $contact?->id,
            'assigned_to' => $assignee,
            'type' => in_array($data['type'] ?? null, Task::TYPES, true) ? $data['type'] : 'follow_up',
            'title' => mb_substr($title, 0, 200),
            'notes' => $data['notes'] ?? null,
            'priority' => ($data['priority'] ?? null) === 'high' ? 'high' : 'normal',
            'due_at' => $due,
            'remind_at' => ($data['remind'] ?? false) === true ? $due->subMinutes(15) : null,
            'created_by' => $by->id,
        ]);

        $this->refreshNextTask($contact);
        if ($contact !== null) {
            ContactBrief::query()->where('contact_id', $contact->id)->update(['stale' => true]);
        }

        return $task;
    }

    public function complete(Task $task, User $by): Task
    {
        if ($task->completed_at !== null) {
            return $task;
        }

        $task->update(['completed_at' => CarbonImmutable::now(), 'completed_by' => $by->id]);
        if ($task->contact !== null) {
            $this->timeline->system($task->contact, "Task done: {$task->title}", $by->id);
        }
        $this->refreshNextTask($task->contact);
        TaskCompleted::dispatch($task->tenant_id, $task->id, $by->id);

        return $task;
    }

    public function reopen(Task $task): Task
    {
        $task->update(['completed_at' => null, 'completed_by' => null]);
        $this->refreshNextTask($task->contact);

        return $task;
    }

    public function reschedule(Task $task, string $due, User $by): Task
    {
        $dueAt = $this->dueAt($due, null, $by);
        $task->update(['due_at' => $dueAt, 'remind_at' => $task->remind_at !== null ? $dueAt->subMinutes(15) : null, 'reminded_at' => null]);
        $this->refreshNextTask($task->contact);

        return $task;
    }

    /**
     * Tasks this user may see: assigned to them (or, for assistants, to their agent), or about a
     * contact they can see.
     *
     * @return Builder<Task>
     */
    public function visibleTo(User $user): Builder
    {
        $mine = $user->kind === UserKind::Assistant && $user->assisted_user_id !== null ? [$user->id, $user->assisted_user_id] : [$user->id];
        $contactIds = $this->visibility->query($user)->select('id');

        return Task::query()->where(fn (Builder $q) => $q->whereIn('assigned_to', $mine)->orWhereIn('contact_id', $contactIds));
    }

    public function canSee(User $user, Task $task): bool
    {
        return $this->visibleTo($user)->whereKey($task->id)->exists();
    }

    /**
     * Quick picks ("today" = 5 pm today, "tomorrow" and "next_week" = 9 am) in the user's time
     * zone, or an exact date-time.
     */
    private function dueAt(?string $quick, ?string $exact, User $by): CarbonImmutable
    {
        $tz = $by->timezone !== null && $by->timezone !== '' ? $by->timezone : 'America/New_York';
        $now = CarbonImmutable::now($tz);

        $local = match ($quick) {
            'today' => $now->setTime(17, 0)->max($now->addHour()->startOfHour()),
            'tomorrow' => $now->addDay()->setTime(9, 0),
            'next_week' => $now->next('Monday')->setTime(9, 0),
            default => $exact !== null && $exact !== '' ? CarbonImmutable::parse($exact, $tz) : throw ValidationException::withMessages(['due' => 'When is it due?']),
        };

        return $local->utc();
    }

    private function refreshNextTask(?Contact $contact): void
    {
        if ($contact === null) {
            return;
        }

        $next = Task::query()->where('contact_id', $contact->id)->whereNull('completed_at')->min('due_at');
        $contact->newQuery()->whereKey($contact->id)->update(['next_task_at' => $next]);
    }
}
