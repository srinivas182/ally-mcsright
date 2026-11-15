<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Models\Task;

/* Sprint 17D-3: agents' weekly hours for website booking (specs/websites/13). */

beforeEach(function (): void {
    // Wednesday 10:00 in Chicago (the brokerage's time zone when none is set).
    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->jane = User::factory()->create(['first_name' => 'Jane']);
});

function availHours(array $wed): array
{
    return ['mon' => [], 'tue' => [], 'wed' => $wed, 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []];
}

it('is off until the agent turns it on, then offers their hours after the notice, minus their open tasks', function (): void {
    $booking = app(Availability::class);
    expect($booking->for($this->jane->id)['enabled'])->toBeFalse()
        ->and($booking->slots($this->jane))->toBe([]);

    $this->actingAs($this->jane)->post('/settings/availability', ['enabled' => true, 'hours' => availHours([['09:00', '14:00']]), 'slot_minutes' => 30, 'notice_hours' => 2])
        ->assertSessionHasNoErrors();
    Task::query()->create(['assigned_to' => $this->jane->id, 'type' => 'call', 'title' => 'Call Bob', 'priority' => 'normal',
        'due_at' => CarbonImmutable::parse('2026-06-03 13:00', 'America/Chicago')->utc()]);

    $labels = array_map(fn (CarbonImmutable $t): string => $t->format('D H:i'), $booking->slots($this->jane));
    // 10:00 now + 2 hours' notice → from 12:00; the 13:00 call takes 13:00–13:30; the last call ends by 14:00.
    expect($labels)->toBe(['Wed 12:00', 'Wed 12:30', 'Wed 13:30']);

    $this->actingAs($this->jane)->get('/settings/availability')->assertInertia(fn (Assert $p) => $p->component('settings/availability')
        ->where('availability.enabled', true)->where('timezone', 'America/Chicago')->where('next', ['Wed Jun 3, 12:00 PM', 'Wed Jun 3, 12:30 PM', 'Wed Jun 3, 1:30 PM']));
});

it('books a slot as a high-priority task with a reminder, and refuses a time that is no longer open', function (): void {
    $booking = app(Availability::class);
    $booking->save($this->jane, true, availHours([['12:00', '13:00']]), 30, 0, $this->jane);
    $start = CarbonImmutable::parse('2026-06-03 12:00', 'America/Chicago');

    $task = $booking->book($this->jane, null, $start, 'showing', 'Showing of 1 Elm');
    expect($task->type)->toBe('showing')->and($task->priority)->toBe('high')
        ->and($task->due_at?->equalTo($start))->toBeTrue()
        ->and($task->remind_at?->equalTo($start->subMinutes(30)))->toBeTrue();

    expect(fn () => $booking->book($this->jane, null, $start, 'call', ''))->toThrow(ValidationException::class);
    expect($booking->slots($this->jane))->toBe([]); // the showing takes the hour
});

it('cleans the hours it is given and checks the slot length and notice', function (): void {
    $this->actingAs($this->jane)->post('/settings/availability', ['enabled' => true, 'hours' => ['wed' => [['18:00', '09:00'], ['25:00', '26:00'], ['09:00', '10:00']], 'xyz' => [['09:00', '10:00']]],
        'slot_minutes' => 30, 'notice_hours' => 1])->assertSessionHasNoErrors();
    expect(app(Availability::class)->for($this->jane->id)['hours'])->toBe(availHours([['09:00', '10:00']]));

    $this->actingAs($this->jane)->post('/settings/availability', ['enabled' => true, 'hours' => [], 'slot_minutes' => 20, 'notice_hours' => 1])
        ->assertSessionHasErrors('slot_minutes');
    $this->actingAs(User::factory()->hqStaff()->create())->get('/settings/availability')->assertForbidden();
});
