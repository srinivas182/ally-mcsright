<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;

final class TaskController
{
    public function __construct(private readonly Tasks $tasks, private readonly ContactVisibility $visibility) {}

    /**
     * My tasks: overdue, today, upcoming and recently done (in the user's time zone).
     */
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $tz = $user->timezone !== null && $user->timezone !== '' ? $user->timezone : 'America/New_York';
        $endOfToday = CarbonImmutable::now($tz)->endOfDay()->utc();
        $mine = $user->assisted_user_id !== null ? [$user->id, $user->assisted_user_id] : [$user->id];
        $open = Task::query()->whereIn('assigned_to', $mine)->whereNull('completed_at')->with('contact')->orderBy('due_at')->get();

        return Inertia::render('crm/tasks/index', [
            'overdue' => $open->filter(fn (Task $t): bool => $t->due_at->isPast())->map(fn (Task $t): array => self::row($t, $tz))->values(),
            'today' => $open->filter(fn (Task $t): bool => ! $t->due_at->isPast() && $t->due_at->lte($endOfToday))->map(fn (Task $t): array => self::row($t, $tz))->values(),
            'upcoming' => $open->filter(fn (Task $t): bool => $t->due_at->gt($endOfToday))->take(50)->map(fn (Task $t): array => self::row($t, $tz))->values(),
            'done' => Task::query()->whereIn('assigned_to', $mine)->whereNotNull('completed_at')->with('contact')->orderByDesc('completed_at')->limit(10)->get()
                ->map(fn (Task $t): array => self::row($t, $tz))->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate([
            'contact' => ['nullable', 'string', 'size:26'],
            'title' => ['required', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(Task::TYPES)],
            'due' => ['nullable', Rule::in(['today', 'tomorrow', 'next_week'])],
            'due_at' => ['nullable', 'date'],
            'remind' => ['nullable', 'boolean'],
            'priority' => ['nullable', Rule::in(['normal', 'high'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $contact = null;
        if (! empty($data['contact'])) {
            $contact = Contact::query()->where('ulid', $data['contact'])->first();
            abort_if($contact === null || ! $this->visibility->canSee($user, $contact), 404);
        }

        $this->tasks->create($contact, $data, $user);

        return back();
    }

    public function complete(Request $request, Task $task): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->tasks->canSee($user, $task), 404);
        $this->tasks->complete($task, $user);

        return back();
    }

    public function reopen(Request $request, Task $task): RedirectResponse
    {
        abort_unless($this->tasks->canSee(self::user($request), $task), 404);
        $this->tasks->reopen($task);

        return back();
    }

    public function reschedule(Request $request, Task $task): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->tasks->canSee($user, $task), 404);
        $data = $request->validate(['due' => ['required', Rule::in(['today', 'tomorrow', 'next_week'])]]);
        $this->tasks->reschedule($task, $data['due'], $user);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Task $t, string $tz): array
    {
        return [
            'ulid' => $t->ulid, 'title' => $t->title, 'type' => $t->type, 'priority' => $t->priority,
            'due_at' => $t->due_at->setTimezone($tz)->toIso8601String(), 'overdue' => $t->isOverdue(),
            'completed_at' => $t->completed_at?->toIso8601String(), 'remind' => $t->remind_at !== null,
            'contact' => $t->contact !== null ? ['ulid' => $t->contact->ulid, 'name' => $t->contact->name, 'phone' => $t->contact->primary_phone, 'email' => $t->contact->primary_email] : null,
            'message' => $t->message,
            'from_plan' => $t->plan_enrollment_id !== null,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
