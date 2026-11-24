<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Transactions\Deals;

/*
| CM5: the commission sheet. Several agents can share one side of a deal, each paid on their own plan; the sheet shows
| the side, each agent's share, plan, split and cap.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $this->co = User::factory()->create(['first_name' => 'Cara', 'last_name' => 'Cole']);
    $this->other = User::factory()->create(['first_name' => 'Otto']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->deal = app(Deals::class)->create(['type' => 'listing', 'street' => '9 Pine St', 'city' => 'Austin', 'state_code' => 'TX', 'price_cents' => 50_000_000],
        $this->agent, $this->agent);
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '10000',
        'contract_date' => '2026-06-01', 'closing_date' => '2026-07-01'])->assertSessionHasNoErrors();
});

function sheetAgents(array $agents): array
{
    return ['agents' => array_map(fn (array $a): array => ['user' => $a[0]->ulid, 'share' => $a[1]], $agents)];
}

it('splits a deal 60 / 40 between two agents and recalculates straight away', function (): void {
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post("/deals/{$this->deal->ulid}/agents", sheetAgents([[$this->agent, '60'], [$this->co, '40']]))->assertSessionHasNoErrors();

    $calc = Calculation::query()->findOrFail($this->deal->fresh()->calculation_id);
    $net = collect($calc->result['agents'])->pluck('agent_net_cents')->all();
    // GCI $10,000: Pat's part is $6,000 and Cara's $4,000, each at 80%.
    expect($net)->toBe([480_000, 320_000]);

    $this->actingAs($this->owner)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('dealAgents.0.name', $this->agent->name)->where('dealAgents.0.share', '60')->where('dealAgents.0.primary', true)
        ->where('dealAgents.1.name', $this->co->name)->where('dealAgents.1.share', '40')
        ->where('whoGetsPaid.side', 'Seller (listing) side')
        ->where('whoGetsPaid.agents.0.share_percent', '60')->where('whoGetsPaid.agents.0.split', '80 / 20')
        ->where('whoGetsPaid.agents.1.net_cents', 320_000));
});

it('lets a co-agent see the deal, but not someone who is not on it', function (): void {
    $this->actingAs($this->other)->get("/deals/{$this->deal->ulid}")->assertNotFound();
    app(Deals::class)->setAgents($this->deal, sheetAgents([[$this->agent, '50'], [$this->co, '50']])['agents'], $this->owner);
    $this->actingAs($this->co)->get("/deals/{$this->deal->ulid}")->assertOk();
    $this->actingAs($this->other)->get("/deals/{$this->deal->ulid}")->assertNotFound();
});

it('refuses shares that do not add up, a missing deal agent, a repeated agent and agents changing it themselves', function (): void {
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/agents", sheetAgents([[$this->agent, '60'], [$this->co, '30']]))->assertSessionHasErrors('agents');
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/agents", sheetAgents([[$this->co, '100']]))->assertSessionHasErrors('agents');
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/agents", sheetAgents([[$this->agent, '50'], [$this->agent, '50']]))->assertSessionHasErrors('agents.1.user');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/agents", sheetAgents([[$this->agent, '50'], [$this->co, '50']]))->assertForbidden();
    expect(app(Deals::class)->agents($this->deal))->toBe([['user_id' => $this->agent->id, 'share_percent' => '100']]);
});

it('gives choices only to people who may change the agents', function (): void {
    $this->actingAs($this->owner)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->has('agentChoices', 4));
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('agentChoices', null));
});
