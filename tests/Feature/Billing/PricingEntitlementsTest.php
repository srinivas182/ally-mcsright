<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Catalogue;
use Modules\Billing\Entitlements;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\AgentSubscription;
use Modules\Billing\Pricing;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $this->catalogue = app(Catalogue::class);
    $this->catalogue->saveTiers([['min_paying_agents' => 1, 'percent' => '20'], ['min_paying_agents' => 25, 'percent' => '25'], ['min_paying_agents' => 100, 'percent' => '30']]);
    $this->basic = $this->catalogue->savePlan(null, ['key' => 'basic', 'name' => 'Basic', 'list_cents' => 4_000, 'floor_cents' => 2_400, 'features' => ['backoffice.basic'], 'sort' => 1]);
    $this->pro = $this->catalogue->savePlan(null, ['key' => 'pro', 'name' => 'Pro', 'list_cents' => 10_000, 'floor_cents' => 6_000, 'features' => ['backoffice.basic', 'crm.ai', 'crm.intelligence'], 'sort' => 2]);
});

function agentPays(string $policy = 'minimal'): void
{
    app(Pricing::class)->configure('agent_pays', $policy, test()->owner);
    app(Entitlements::class)->forget();
}

function subscribe(User $user, AgentPlan $plan, int $price): AgentSubscription
{
    $sub = AgentSubscription::query()->create(['user_id' => $user->id, 'agent_plan_id' => $plan->id, 'status' => 'active', 'price_cents' => $price]);
    app(Entitlements::class)->forget();

    return $sub;
}

it('changes nothing while the brokerage pays for everyone', function (): void {
    expect(app(Entitlements::class)->allows($this->agent, 'crm.ai'))->toBeTrue();
    $this->actingAs($this->agent)->get('/contacts')->assertOk();
    $this->actingAs($this->agent)->get('/today')->assertOk();
});

it('keeps HQ prices sensible and gives brokerages 60 days before an increase', function (): void {
    expect(fn () => $this->catalogue->savePlan(null, ['key' => 'bad', 'name' => 'Bad', 'list_cents' => 1_000, 'floor_cents' => 2_000, 'features' => []]))->toThrow(ValidationException::class)
        ->and(fn () => $this->catalogue->savePlan(null, ['key' => 'bad', 'name' => 'Bad', 'list_cents' => 1_000, 'floor_cents' => 500, 'features' => ['teleport']]))->toThrow(ValidationException::class);

    $this->catalogue->savePlan($this->pro, ['key' => 'pro', 'name' => 'Pro', 'list_cents' => 12_000, 'floor_cents' => 7_000, 'features' => ['backoffice.basic', 'crm.ai']]);
    expect($this->catalogue->priceInForce($this->pro->fresh(), 'floor'))->toBe(6_000)->and($this->catalogue->priceInForce($this->pro->fresh(), 'list'))->toBe(10_000);
    $this->travel(61)->days();
    expect($this->catalogue->priceInForce($this->pro->fresh(), 'floor'))->toBe(7_000);
});

it('lets the brokerage price between floor and list, and shows what it earns', function (): void {
    $pricing = app(Pricing::class);
    expect(fn () => $pricing->setPrice($this->pro, 5_000, true, $this->owner))->toThrow(ValidationException::class, 'floor')
        ->and(fn () => $pricing->setPrice($this->pro, 11_000, true, $this->owner))->toThrow(ValidationException::class);

    $pricing->setPrice($this->pro, 7_500, true, $this->owner);
    expect($pricing->priceFor($this->pro))->toBe(7_500)
        ->and($pricing->earningsPreview($this->pro, 7_500))->toMatchArray([
            'commission_percent' => '20', 'commission_cents' => 1_200, 'markup_cents' => 1_500, 'total_cents' => 2_700, 'rightally_cents' => 4_800,
        ]);
});

it('moves existing agents to a higher price only after 30 days, and to a lower one at once', function (): void {
    $pricing = app(Pricing::class);
    $pricing->setPrice($this->pro, 7_000, true, $this->owner);
    $sub = subscribe($this->agent, $this->pro, 7_000);

    $pricing->setPrice($this->pro, 8_000, true, $this->owner);
    expect($pricing->priceFor($this->pro))->toBe(8_000)->and($sub->fresh()?->price_cents)->toBe(7_000);
    $this->travel(31)->days();
    $this->artisan('billing:apply-price-changes')->assertSuccessful();
    expect($sub->fresh()?->price_cents)->toBe(8_000);

    $pricing->setPrice($this->pro, 6_500, true, $this->owner);
    expect($sub->fresh()?->price_cents)->toBe(6_500);
});

it('gives agents without a plan the basics, and their plan\'s features once they have one', function (): void {
    agentPays('minimal');

    $this->actingAs($this->agent)->get('/contacts')->assertRedirect('/plans?feature=backoffice.basic');
    $this->actingAs($this->agent)->get('/my-plan')->assertOk();

    subscribe($this->agent, $this->basic, 2_400);
    $this->actingAs($this->agent)->get('/contacts')->assertOk();
    $this->actingAs($this->agent)->get('/today')->assertRedirect('/plans?feature=crm.intelligence');

    AgentSubscription::query()->update(['agent_plan_id' => $this->pro->id]);
    app(Entitlements::class)->forget();
    $this->actingAs($this->agent)->get('/today')->assertOk();

    $assistant = User::factory()->assistantOf($this->agent)->create();
    expect(app(Entitlements::class)->allows($assistant, 'crm.intelligence'))->toBeTrue()
        ->and(app(Entitlements::class)->allows($this->owner, 'crm.ai'))->toBeTrue();
});

it('sends agents without a plan to the plans page when the brokerage requires a plan', function (): void {
    agentPays('blocked');

    $this->actingAs($this->agent)->get('/my-plan')->assertRedirect('/plans');
    $this->actingAs($this->agent)->get('/settings/profile')->assertOk();
    $this->actingAs($this->agent)->get('/plans')->assertInertia(fn (Assert $page) => $page
        ->component('billing/plans')->where('blocked', true)->where('plans.0.name', 'Basic')->where('plans.1.list_cents', 10_000));
    $this->actingAs($this->owner)->get('/contacts')->assertOk();
});

it('shows only offered plans at the brokerage price, and keeps settings to owners and HQ', function (): void {
    agentPays();
    app(Pricing::class)->setPrice($this->basic, 3_000, false, $this->owner);
    app(Pricing::class)->setPrice($this->pro, 8_000, true, $this->owner);

    $this->actingAs($this->agent)->get('/plans')->assertInertia(fn (Assert $page) => $page
        ->has('plans', 1)->where('plans.0.name', 'Pro')->where('plans.0.price_cents', 8_000));
    $menu = fn (User $u) => collect($this->actingAs($u)->get('/plans')->viewData('page')['props']['navigation'])->flatMap(fn ($g) => collect($g['items'])->pluck('title'))->all();
    expect($menu($this->agent))->toContain('Plans');

    $this->actingAs($this->agent)->get('/settings/agent-plans')->assertForbidden();
    $this->actingAs($this->owner)->get('/settings/agent-plans')->assertInertia(fn (Assert $page) => $page
        ->where('model', 'agent_pays')->where('plans.1.earnings.total_cents', 1_200 + 2_000));
    expect($this->actingAs($this->owner)->get('http://app.rightally.io/hq/billing')->status())->not->toBe(200);
});
