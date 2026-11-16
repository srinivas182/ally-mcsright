<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\Access\Team;
use Modules\Crm\Actions\Tasks;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $this->ben = User::factory()->create(['first_name' => 'Ben']);
    $this->lee = User::factory()->create(['first_name' => 'Lee']);
    $team = Team::query()->create(['name' => 'Lee Team', 'leader_user_id' => $this->lee->id]);
    DB::table('team_members')->insert(['tenant_id' => $this->tenant->id, 'team_id' => $team->id, 'user_id' => $this->ann->id, 'joined_at' => now()]);

    $deal = fn (User $a, string $status, ?string $closedOn, int $price, int $gci, ?string $closing = null): Deal => Deal::query()->create([
        'type' => 'buyer', 'status' => $status, 'agent_user_id' => $a->id, 'street' => "{$price} Oak St", 'city' => 'Austin', 'state_code' => 'TX',
        'price_cents' => $price * 100, 'gci_cents' => $gci * 100, 'closed_on' => $closedOn, 'closing_date' => $closing ?? $closedOn]);
    $deal($this->ann, 'closed', '2026-03-10', 400000, 12000);
    $deal($this->ann, 'closed', '2026-10-05', 600000, 18000);
    $deal($this->ben, 'closed', '2026-06-20', 300000, 9000);
    $deal($this->ann, 'closed', '2025-05-01', 350000, 10000);
    $deal($this->ben, 'under_contract', null, 500000, 15000, '2026-12-01');
});

it('shows the broker dashboard with last year, trend, pipeline and drill-down', function (): void {
    $this->actingAs($this->owner)->get('/insights')->assertInertia(fn (Assert $page) => $page
        ->component('insights/index')->where('scope', 'everyone')->where('money', true)
        ->where('now.sides', 3)->where('now.volume_cents', 130000000)->where('now.gci_cents', 3900000)
        ->where('last_year.sides', 1)->count('months', 12)->where('months.11.sides', 0)->where('months.10.sides', 1)
        ->where('pending.deals', 1)->where('pending.buckets.0.deals', 1)->where('pending.buckets.0.gci_cents', 1500000)
        ->where('agents.0.name', $this->ann->name)->where('agents.0.sides', 2)->where('people.active', 3)
        ->where('canSetGoal', true));

    $this->actingAs($this->owner)->getJson('/insights/deals?metric=closed&range=ytd')->assertOk()->assertJsonCount(3, 'deals');
    $this->actingAs($this->owner)->getJson('/insights/deals?metric=closed&month=2026-10')->assertJsonCount(1, 'deals')->assertJsonPath('deals.0.street', '600000 Oak St');
    $this->actingAs($this->owner)->getJson("/insights/deals?metric=closed&range=ytd&agent={$this->ben->ulid}")->assertJsonCount(1, 'deals');
    $this->actingAs($this->owner)->getJson('/insights/deals?metric=pending&within=30')->assertJsonCount(1, 'deals')->assertJsonPath('deals.0.gci_cents', 1500000);

    $this->actingAs($this->owner)->post('/insights/goals', ['for' => 'brokerage', 'year' => 2026, 'gci' => 100000, 'sides' => 10])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get('/insights')->assertInertia(fn (Assert $page) => $page
        ->where('goal.gci_cents', 10000000)->where('goal.sides', 10)->where('goal.ytd.sides', 3));
});

it('limits a team lead to their team and hides pay without commission access', function (): void {
    app(Roles::class)->all();
    app(Roles::class)->assign($this->lee, Role::query()->where('template_key', 'team_lead')->firstOrFail(), $this->owner);
    $lee = $this->lee->fresh();

    $this->actingAs($lee)->get('/insights')->assertInertia(fn (Assert $page) => $page
        ->where('scope', 'your team')->where('money', false)->where('now.sides', 2)->where('now.gci_cents', null)
        ->where('sources', null)->where('goal', null)->where('canSetGoal', false));
    $this->actingAs($lee)->getJson("/insights/deals?metric=closed&range=ytd&agent={$this->ben->ulid}")->assertForbidden();
    $this->actingAs($lee)->getJson('/insights/deals?metric=closed&range=ytd')->assertJsonCount(2, 'deals')->assertJsonPath('deals.0.gci_cents', null);
    $this->actingAs($lee)->get("/insights/agents/{$this->ann->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('insights/me')->where('ytd.sides', 2)->where('ytd.gci_cents', null)->where('cap', null));
    $this->actingAs($lee)->get("/insights/agents/{$this->ben->ulid}")->assertForbidden();
    $this->actingAs($lee)->post('/insights/goals', ['for' => 'brokerage', 'year' => 2026, 'gci' => 1])->assertForbidden();
});

it('gives every agent their own success dashboard with this week\'s priorities', function (): void {
    $this->actingAs($this->ann)->get('/insights')->assertRedirect('/insights/me');
    app(Tasks::class)->create(null, ['title' => 'Call Bob', 'due_at' => now()->subDay()->toIso8601String(), 'assigned_to' => $this->ann->id], $this->ann);

    $this->actingAs($this->ann)->post('/insights/goals', ['for' => 'me', 'year' => 2026, 'gci' => 50000, 'sides' => 6])->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->get('/insights/me')->assertInertia(fn (Assert $page) => $page
        ->component('insights/me')->where('isSelf', true)->where('ytd.sides', 2)->where('ytd.gci_cents', 3000000)->where('last_year.sides', 1)
        ->where('goal.sides', 6)->where('goal.gci_cents', 5000000)->where('habits.overdue_tasks', 1)
        ->where('week', fn ($w) => collect($w)->pluck('text')->contains('1 overdue task')
            && collect($w)->contains(fn ($i) => str_contains($i['text'], 'behind your goal pace'))));
    $this->actingAs($this->ann)->getJson('/insights/deals?metric=closed&range=ytd')->assertJsonCount(2, 'deals');
    $this->actingAs($this->ann)->get("/insights/agents/{$this->ben->ulid}")->assertForbidden();
});
