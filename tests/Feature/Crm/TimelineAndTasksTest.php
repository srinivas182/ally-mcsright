<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Task;
use Modules\Crm\Notifications\TaskReminder;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 14:00:00', 'UTC')); // 9 am in New York
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['timezone' => 'America/New_York']);
    $this->other = User::factory()->create();
    $this->assistant = User::factory()->assistantOf($this->agent)->create();
    $this->contact = app(ManageContacts::class)->create(['first_name' => 'Pat', 'last_name' => 'Buyer', 'email' => 'pat@x.test', 'source' => 'zillow'], $this->agent);
    Notification::fake();
});

it('logs notes, calls and meetings on the timeline and updates last activity', function (): void {
    $url = "/contacts/{$this->contact->ulid}/activities";

    $this->actingAs($this->agent)->post($url, ['type' => 'note', 'body' => 'Wants a fenced yard'])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post($url, ['type' => 'call', 'outcome' => 'left_voicemail'])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post($url, ['type' => 'meeting', 'body' => 'Buyer consultation', 'duration_minutes' => 45])->assertSessionHasNoErrors();

    expect(Activity::query()->where('type', '!=', 'system')->pluck('type')->all())->toBe(['note', 'call', 'meeting'])
        ->and($this->contact->fresh()?->last_activity_at?->toIso8601String())->toBe(now()->toIso8601String());

    $this->actingAs($this->agent)->post($url, ['type' => 'call'])->assertSessionHasErrors('outcome');
    $this->actingAs($this->agent)->post($url, ['type' => 'note', 'body' => ''])->assertSessionHasErrors('body');
    $this->actingAs($this->agent)->post($url, ['type' => 'note', 'body' => 'x', 'occurred_at' => now()->addDay()->toDateTimeString()])->assertSessionHasErrors('occurred_at');
});

it('adds automatic entries when a contact is added, changes stage or changes owner', function (): void {
    $company = app(ManageContacts::class)->create(['first_name' => 'Cal', 'email' => 'cal@x.test'], $this->owner);
    app(ManageContacts::class)->update($company, ['lifecycle' => 'active'], $this->owner);
    app(ManageContacts::class)->assignOwner($company, $this->agent, $this->owner);

    expect(Activity::query()->where('contact_id', $company->id)->where('type', 'system')->orderBy('id')->pluck('body')->all())->toBe([
        'Contact added by '.$this->owner->name.' (source: manual)',
        'Stage changed from lead to active',
        'Assigned to '.$this->agent->name.' by '.$this->owner->name,
    ]);
});

it('keeps the timeline private to people who can see the contact', function (): void {
    $this->actingAs($this->other)->post("/contacts/{$this->contact->ulid}/activities", ['type' => 'note', 'body' => 'snoop'])->assertNotFound();
    $note = app(Timeline::class)->log($this->contact, ['type' => 'note', 'body' => 'Pin me'], $this->agent);
    $this->actingAs($this->other)->post("/activities/{$note->ulid}/pin")->assertNotFound();

    $this->actingAs($this->agent)->post("/activities/{$note->ulid}/pin");
    $this->actingAs($this->agent)->get("/contacts/{$this->contact->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('timeline.0.body', 'Pin me')->where('timeline.0.pinned', true));
});

it('creates tasks with quick due dates in the agent\'s time zone and tracks the next task', function (): void {
    $this->actingAs($this->agent)->post('/tasks', ['contact' => $this->contact->ulid, 'title' => 'Send listings', 'due' => 'today', 'remind' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post('/tasks', ['contact' => $this->contact->ulid, 'title' => 'Follow up', 'due' => 'tomorrow'])->assertSessionHasNoErrors();

    $today = Task::query()->where('title', 'Send listings')->sole();
    expect($today->due_at->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-18 17:00')
        ->and($today->remind_at?->setTimezone('America/New_York')->format('H:i'))->toBe('16:45')
        ->and(Task::query()->where('title', 'Follow up')->sole()->due_at->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-19 09:00')
        ->and($this->contact->fresh()?->getAttribute('next_task_at'))->not->toBeNull();
});

it('completes a task in one tap, notes it on the timeline, and can reopen or reschedule it', function (): void {
    $task = app(Tasks::class)->create($this->contact, ['title' => 'Call about inspection', 'due' => 'today'], $this->agent);

    $this->actingAs($this->agent)->post("/tasks/{$task->ulid}/complete")->assertSessionHasNoErrors();
    expect($task->fresh()?->completed_at)->not->toBeNull()
        ->and(Activity::query()->where('body', 'Task done: Call about inspection')->exists())->toBeTrue()
        ->and($this->contact->fresh()?->getAttribute('next_task_at'))->toBeNull();

    $this->actingAs($this->agent)->post("/tasks/{$task->ulid}/reopen");
    $this->actingAs($this->agent)->post("/tasks/{$task->ulid}/reschedule", ['due' => 'next_week']);
    expect($task->fresh()?->completed_at)->toBeNull()
        ->and($task->fresh()?->due_at->setTimezone('America/New_York')->format('l H:i'))->toBe('Monday 09:00');
});

it('keeps tasks private: others cannot see or complete them; tasks without a contact are the assignee\'s alone', function (): void {
    $task = app(Tasks::class)->create($this->contact, ['title' => 'Private follow-up', 'due' => 'today'], $this->agent);
    $solo = app(Tasks::class)->create(null, ['title' => 'Renew licence', 'due' => 'next_week'], $this->agent);

    $this->actingAs($this->other)->post("/tasks/{$task->ulid}/complete")->assertNotFound();
    $this->actingAs($this->owner)->post("/tasks/{$solo->ulid}/complete")->assertNotFound();
    expect(app(Tasks::class)->canSee($this->assistant, $solo))->toBeTrue();
});

it('shows my tasks grouped as overdue, today and upcoming, including for assistants', function (): void {
    $tasks = app(Tasks::class);
    $tasks->create($this->contact, ['title' => 'Overdue one', 'due_at' => '2026-11-17 10:00'], $this->agent);
    $tasks->create($this->contact, ['title' => 'Today one', 'due' => 'today'], $this->agent);
    $tasks->create(null, ['title' => 'Later one', 'due' => 'next_week'], $this->agent);

    foreach ([$this->agent, $this->assistant] as $user) {
        $this->actingAs($user)->get('/tasks')->assertInertia(fn (Assert $page) => $page->component('crm/tasks/index')
            ->where('overdue.0.title', 'Overdue one')->where('today.0.title', 'Today one')->where('upcoming.0.title', 'Later one'));
    }
});

it('emails each reminder once, at the reminder time, and never for done tasks', function (): void {
    $tasks = app(Tasks::class);
    $remind = $tasks->create($this->contact, ['title' => 'Showing at 5', 'due' => 'today', 'remind' => true], $this->agent);
    $done = $tasks->create($this->contact, ['title' => 'Already done', 'due' => 'today', 'remind' => true], $this->agent);
    $tasks->complete($done, $this->agent);

    $this->artisan('crm:task-reminders')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-11-18 21:46:00', 'UTC')); // 4:46 pm New York
    $this->artisan('crm:task-reminders')->assertSuccessful();
    $this->artisan('crm:task-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->agent, TaskReminder::class, 1);
    expect($remind->fresh()?->reminded_at)->not->toBeNull();
});
