<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Pipelines;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\PlanEnrollment;
use Modules\Crm\Models\Stage;
use Modules\Crm\Models\Task;
use Modules\Crm\SmartLists;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-29 15:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Maria']);
    $this->other = User::factory()->create();
    $this->contacts = app(ManageContacts::class);
    $this->plans = app(FollowUpPlans::class);
});

function listNames(User $user, string $key): array
{
    $filters = app(SmartLists::class)->filtersFor($key, $user);

    return app(SmartLists::class)->apply(app(ContactVisibility::class)->query($user), $filters)->orderBy('first_name')->pluck('first_name')->all();
}

it('lists who is due a keep-in-touch call by A/B/C/D ranking', function (): void {
    foreach ([['Ann', 'A', 40], ['Ben', 'B', 40], ['Cal', 'C', null], ['Dee', null, 400]] as [$name, $tier, $daysAgo]) {
        $c = $this->contacts->create(['first_name' => $name, 'email' => strtolower($name).'@x.test'], $this->agent);
        $c->forceFill(['rhythm' => $tier, 'last_activity_at' => $daysAgo !== null ? now()->subDays($daysAgo) : null])->save();
    }

    expect(listNames($this->agent, 'keep-in-touch'))->toBe(['Ann', 'Cal']);
});

it('finds birthdays and home anniversaries this week, across the new year', function (): void {
    $jan = $this->contacts->create(['first_name' => 'Jan', 'email' => 'jan@x.test'], $this->agent);
    $jan->forceFill(['birthday' => '1990-01-02', 'closed_on' => '2023-12-30'])->save();
    $feb = $this->contacts->create(['first_name' => 'Feb', 'email' => 'feb@x.test'], $this->agent);
    $feb->forceFill(['birthday' => '1985-02-14', 'closed_on' => '2024-03-01'])->save();

    expect(listNames($this->agent, 'birthdays-week'))->toBe(['Jan'])
        ->and(listNames($this->agent, 'anniversaries-week'))->toBe(['Jan']);
});

it('records the closing date and ranks a new past client A when their deal closes', function (): void {
    $pat = $this->contacts->create(['first_name' => 'Pat', 'email' => 'pat@x.test'], $this->agent);
    app(Pipelines::class)->all();
    $closed = Stage::query()->where('name', 'Closed')->where('pipeline_id', Pipeline::query()->where('name', 'Buyers')->value('id'))->sole();

    app(Pipelines::class)->move($pat, $closed, $this->agent);

    expect($pat->fresh()?->closed_on?->toDateString())->toBe('2026-12-29')->and($pat->fresh()?->rhythm)->toBe('A');
    $this->actingAs($this->agent)->patch("/contacts/{$pat->ulid}", ['rhythm' => 'Z'])->assertSessionHasErrors('rhythm');
});

it('runs a plan: steps arrive as tasks on their day, texts respect consent, messages are personalised', function (): void {
    $sam = $this->contacts->create(['first_name' => 'Sam', 'email' => 'sam@x.test', 'phone' => '6015550101'], $this->agent);
    $plan = $this->plans->plansFor($this->agent)->firstWhere('name', 'New online lead (10 days)');
    expect(FollowUpPlan::query()->count())->toBe(4);

    $this->actingAs($this->agent)->post("/contacts/{$sam->ulid}/plans", ['plan' => $plan->ulid])->assertSessionHasNoErrors();

    $tasks = Task::query()->whereNotNull('plan_enrollment_id')->orderBy('id')->get();
    expect($tasks->pluck('type')->all())->toBe(['call', 'email'])
        ->and($tasks[1]->title)->toContain('no texting consent')
        ->and($tasks[1]->message)->toStartWith("Hi Sam, it's Maria.");

    $this->travel(1)->days();
    $this->artisan('crm:run-plans')->assertSuccessful();
    expect(Task::query()->whereNotNull('plan_enrollment_id')->count())->toBe(3);
    $this->actingAs($this->agent)->post("/contacts/{$sam->ulid}/plans", ['plan' => $plan->ulid])->assertSessionHasErrors('plan');
});

it('stops the plan when the person responds or the deal moves on, removing open plan tasks', function (): void {
    $plan = $this->plans->plansFor($this->agent)->firstWhere('name', 'New online lead (10 days)');
    $sam = $this->contacts->create(['first_name' => 'Sam', 'email' => 'sam@x.test'], $this->agent);
    $kim = $this->contacts->create(['first_name' => 'Kim', 'email' => 'kim@x.test'], $this->agent);
    $this->plans->enroll($sam, $plan, $this->agent);
    $this->plans->enroll($kim, $plan, $this->agent);

    app(Timeline::class)->log($sam, ['type' => 'call', 'outcome' => 'connected'], $this->agent);
    expect(PlanEnrollment::query()->where('contact_id', $sam->id)->value('status'))->toBe('stopped')
        ->and(Task::query()->where('contact_id', $sam->id)->whereNotNull('plan_enrollment_id')->count())->toBe(0);

    app(Pipelines::class)->all();
    app(Pipelines::class)->move($kim, Stage::query()->where('name', 'Under contract')->firstOrFail(), $this->agent);
    $this->contacts->update($kim->fresh(), ['lifecycle' => 'under_contract'], $this->agent);
    $this->travel(1)->days();
    $this->artisan('crm:run-plans');
    expect(PlanEnrollment::query()->where('contact_id', $kim->id)->value('status'))->toBe('stopped');
});

it('enrols new contacts automatically by source, for brokerage plans set up by owners', function (): void {
    $plan = $this->plans->save(null, ['name' => 'Zillow speed plan', 'trigger' => 'source', 'trigger_value' => 'zillow', 'shared' => true,
        'steps' => [['day' => 0, 'type' => 'call', 'title' => 'Call within 5 minutes']]], $this->owner);

    $lead = $this->contacts->create(['first_name' => 'Zed', 'email' => 'zed@x.test', 'phone' => '6015550177', 'source' => 'zillow'], $this->agent);

    expect(PlanEnrollment::query()->where('contact_id', $lead->id)->where('plan_id', $plan->id)->exists())->toBeTrue()
        ->and(Task::query()->where('contact_id', $lead->id)->value('title'))->toBe('Call within 5 minutes');
});

it('checks templates for Fair Housing wording and keeps plan editing to its owners', function (): void {
    expect(fn () => $this->plans->save(null, ['name' => 'Bad', 'steps' => [['day' => 0, 'type' => 'email', 'title' => 'Send listing', 'message' => 'A safe neighborhood, great for kids!']]], $this->agent))
        ->toThrow(ValidationException::class);

    $shared = $this->plans->plansFor($this->agent)->first();
    expect(fn () => $this->plans->save($shared, ['name' => 'Mine now', 'steps' => [['day' => 0, 'type' => 'call', 'title' => 'x']]], $this->agent))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->plans->save(null, ['name' => 'Auto', 'trigger' => 'source', 'trigger_value' => 'zillow', 'shared' => true, 'steps' => [['day' => 0, 'type' => 'call', 'title' => 'x']]], $this->agent))
        ->toThrow(ValidationException::class);

    $hidden = $this->contacts->create(['first_name' => 'Hid', 'email' => 'hid@x.test'], $this->other);
    $this->actingAs($this->agent)->post("/contacts/{$hidden->ulid}/plans", ['plan' => $shared->ulid])->assertNotFound();
});
