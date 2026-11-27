<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\Models\PlanAssignment;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Compensation\StarterPlans;
use Modules\Core\Identity\Actions\AcceptInvitation;
use Modules\Core\Identity\Models\Invitation;

/* AG6: when an owner invites an agent they choose the sponsor (or none: under the company) and the commission plan
   the agent starts on (or the default plan); both take effect when the invitation is accepted. */

beforeEach(function (): void {
    $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->andy = User::factory()->create(['first_name' => 'Andy']);
    app(StarterPlans::class)->seed();
    $this->flat = Plan::query()->where('name', StarterPlans::FLAT)->sole();
});

it('offers sponsors and published plans on the invite form', function (): void {
    $this->actingAs($this->owner)->get('/users')->assertInertia(fn (Assert $p) => $p
        ->where('plans.0.name', StarterPlans::SHARE)->where('plans.0.default', true)->where('plans.1.name', StarterPlans::FLAT)
        ->where('sponsors', fn ($s): bool => collect($s)->pluck('name')->contains('Andy '.$this->andy->last_name)));
});

it('puts the new agent under the chosen sponsor, on the chosen plan, once they accept', function (): void {
    $this->actingAs($this->owner)->post('/users/invitations', ['email' => 'nina@acme.test', 'first_name' => 'Nina', 'last_name' => 'Ortiz', 'kind' => 'agent',
        'sponsor' => $this->andy->ulid, 'plan' => $this->flat->ulid])->assertSessionHasNoErrors();
    $invitation = Invitation::query()->where('email', 'nina@acme.test')->sole();
    expect($invitation->extras)->toBe(['comp_plan' => $this->flat->ulid]);

    $nina = app(AcceptInvitation::class)->handle($invitation, 'Nina', 'Ortiz', 'a-Long-password-123!');

    expect(app(Sponsorships::class)->sponsorOf($nina->id, CarbonImmutable::now()))->toBe($this->andy->id)
        ->and(PlanAssignment::query()->where('agent_user_id', $nina->id)->sole()->plan_id)->toBe($this->flat->id);
});

it('leaves an agent invited with no sponsor and no plan under the company, on the default plan', function (): void {
    $this->actingAs($this->owner)->post('/users/invitations', ['email' => 'sam@acme.test', 'first_name' => 'Sam', 'last_name' => 'Lee', 'kind' => 'agent'])->assertSessionHasNoErrors();
    $sam = app(AcceptInvitation::class)->handle(Invitation::query()->where('email', 'sam@acme.test')->sole(), 'Sam', 'Lee', 'a-Long-password-123!');

    expect(app(Sponsorships::class)->sponsorOf($sam->id, CarbonImmutable::now()))->toBeNull()
        ->and(PlanAssignment::query()->where('agent_user_id', $sam->id)->exists())->toBeFalse();
});

it('refuses an unknown plan or sponsor', function (): void {
    $this->actingAs($this->owner)->post('/users/invitations', ['email' => 'x@acme.test', 'first_name' => 'X', 'last_name' => 'Y', 'kind' => 'agent',
        'plan' => str_repeat('A', 26)])->assertSessionHasErrors('plan');
    $this->post('/users/invitations', ['email' => 'x@acme.test', 'first_name' => 'X', 'last_name' => 'Y', 'kind' => 'agent',
        'sponsor' => str_repeat('B', 26)])->assertSessionHasErrors('sponsor');
});
