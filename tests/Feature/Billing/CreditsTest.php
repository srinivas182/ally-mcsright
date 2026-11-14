<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\CreditsLow;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 18A: RightAlly credits — pool and agent accounts, consent quotes, menu meter, HQ economics, monthly grants. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Bea']);
    $this->agent = User::factory()->create(['first_name' => 'Jane']);
    $this->ledger = fn (): CreditLedger => app(CreditLedger::class);
    $this->hq = User::factory()->hqStaff()->make(['id' => 999999]);
});

function enforceCredits(bool $on = true): void
{
    app(RateCard::class)->saveEnforced($on, test()->owner);
}

function poolCredits(int $credits): void
{
    app(CreditLedger::class)->add(null, $credits, 'adjust', 'test', test()->owner);
}

it('charges owners and automations to the brokerage pool, once per key, and refunds once', function (): void {
    poolCredits(100);
    $ledger = ($this->ledger)();

    expect($ledger->charge('ai.site_post', $this->owner->id, 'k1'))->toBe(20)
        ->and($ledger->charge('ai.site_post', $this->owner->id, 'k1'))->toBe(0) // same request
        ->and($ledger->charge('ai.crm_ai_responder', null, 'k2'))->toBe(1)
        ->and($ledger->poolSummary())->toMatchArray(['available' => 79, 'used' => 21, 'total' => 100]);

    $ledger->refund('k1', 'blocked output');
    $ledger->refund('k1', 'again');

    expect($ledger->poolSummary()['available'])->toBe(99)
        ->and($ledger->usage())->toMatchArray(['automations' => 1, 'people' => []]);
});

it('charges agents from their own credits first, then the pool when allowed and under the cap', function (): void {
    enforceCredits();
    poolCredits(100);
    $ledger = ($this->ledger)();
    $ledger->allot($this->agent, 3, $this->owner);

    $ledger->charge('ai.site_assist', $this->agent->id, 'a1'); // 2 from own
    expect($ledger->userSummary($this->agent->id))->toMatchArray(['available' => 1, 'used' => 2, 'from_pool' => 0]);

    $ledger->charge('ai.site_assist', $this->agent->id, 'a2'); // own has 1 → pool
    expect($ledger->userSummary($this->agent->id))->toMatchArray(['available' => 1, 'from_pool' => 2])
        ->and($ledger->poolSummary()['available'])->toBe(95);

    $ledger->savePolicy(true, 3, $this->owner);
    expect(fn () => $ledger->charge('ai.site_assist', $this->agent->id, 'a3'))->toThrow(InsufficientCredits::class, 'Jane, you are out of RightAlly Credits');

    $ledger->savePolicy(false, null, $this->owner);
    expect(fn () => $ledger->charge('ai.site_assist', $this->agent->id, 'a4'))->toThrow(InsufficientCredits::class);
});

it('records but never blocks while credits are not enforced', function (): void {
    $ledger = ($this->ledger)();

    expect($ledger->charge('ai.site_post', $this->owner->id, 'n1'))->toBe(20)
        ->and($ledger->poolSummary()['available'])->toBe(-20)
        ->and($ledger->quote('ai.site_post', $this->agent)['enough'])->toBeTrue();

    enforceCredits();
    expect(fn () => $ledger->charge('ai.site_post', $this->owner->id, 'n2'))->toThrow(InsufficientCredits::class, 'out of RightAlly Credits')
        ->and($ledger->quote('ai.site_post', $this->owner))->toMatchArray(['credits' => 20, 'payer' => 'brokerage', 'enough' => false]);
});

it('grants included credits once a month and ends last month\'s leftovers', function (): void {
    $ledger = ($this->ledger)();
    expect($ledger->grantIncluded(100))->toBeTrue()->and($ledger->grantIncluded(100))->toBeFalse();
    poolCredits(50); // bought or added credits carry over
    $ledger->charge('ai.site_post', $this->owner->id, 'g1'); // included used first

    $this->travelTo(CarbonImmutable::parse('2026-07-01 01:00', 'UTC'));
    $ledger->grantIncluded(100);

    expect($ledger->poolSummary())->toMatchArray(['available' => 150, 'included' => 100, 'used' => 0]);
});

it('runs the monthly grant from each brokerage\'s package', function (): void {
    $package = Package::query()->create(['name' => 'Growth', 'monthly_cents' => 49900, 'yearly_cents' => 0, 'ailly_conversations' => 0, 'features' => [], 'active' => true]);
    $package->forceFill(['included_credits' => 500])->save();
    DB::connection(Connections::control())->table('billing_tenant_subscriptions')->insert(['tenant_id' => $this->tenant->id, 'package_id' => $package->id, 'interval' => 'month', 'status' => 'active', 'price_cents' => 49900,
        'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('credits:grant-monthly')->assertSuccessful();
    $this->artisan('credits:grant-monthly')->assertSuccessful();

    expect(app(TenantContext::class)->runAs($this->tenant, fn () => ($this->ledger)()->poolSummary()['available']))->toBe(500);
});

it('emails owners at 80% and 100% used, once each per month', function (): void {
    enforceCredits();
    poolCredits(25);
    $ledger = ($this->ledger)();
    $ledger->charge('ai.site_post', $this->owner->id, 'l1'); // 80%
    $ledger->charge('ai.crm_ask', $this->owner->id, 'l2');
    $ledger->charge('ai.crm_ask', $this->owner->id, 'l3');
    $ledger->charge('ai.crm_ask', $this->owner->id, 'l4');
    $ledger->charge('ai.crm_ask', $this->owner->id, 'l5');
    $ledger->charge('ai.crm_ask', $this->owner->id, 'l6'); // 100%

    Notification::assertSentToTimes($this->owner, CreditsLow::class, 2);
});

it('charges through the AI gateway and refunds nothing on success', function (): void {
    $provider = AiProvider::factory()->create(['key' => 'primary']);
    $model = AiModel::factory()->for($provider, 'provider')->create();
    AiFeatureRoute::query()->create(['feature_key' => 'diagnostics.ping', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.crm_ask']);
    app(ProviderRegistry::class)->fake('primary', $fake = new FakeAiProvider);
    $fake->respond('pong');

    app(AiGateway::class)->run(new AiTask(featureKey: 'diagnostics.ping', promptKey: 'diagnostics.ping', idempotencyKey: 'gw-1', userId: $this->owner->id));

    expect(($this->ledger)()->usage()['features'])->toEqual([['action' => 'ai.crm_ask', 'credits' => 1, 'uses' => 1, 'automation' => false]]);
});

it('shows the consent quote and the menu meter', function (): void {
    poolCredits(40);
    ($this->ledger)()->charge('ai.site_post', $this->owner->id, 'm1');

    $this->actingAs($this->owner)->getJson('/credits/quote?action=ai.site_post')->assertOk()
        ->assertJson(['credits' => 20, 'available' => 20, 'payer' => 'brokerage', 'label' => 'AI blog post draft']);
    $this->actingAs($this->agent)->getJson('/credits/quote?action=bad key')->assertUnprocessable();

    $this->actingAs($this->owner)->get('/settings/credits')->assertInertia(fn (Assert $page) => $page->component('settings/credits')
        ->where('credits', ['available' => 20, 'total' => 40, 'used' => 20, 'scope' => 'brokerage', 'period' => '2026-06', 'enforced' => false])
        ->where('manager', true)->where('usage.people.0', ['name' => $this->owner->name, 'credits' => 20])->has('agents', 1));
    $this->actingAs($this->agent)->get('/settings/credits')->assertInertia(fn (Assert $page) => $page->where('manager', false)->missing('agents')
        ->where('credits.scope', 'you')->where('credits.available', 20));
});

it('lets managers give credits to agents and set the pool rules; agents cannot', function (): void {
    poolCredits(10);
    $this->actingAs($this->agent)->post('/settings/credits/allot', ['user_id' => $this->agent->id, 'credits' => 5])->assertForbidden();
    $this->actingAs($this->owner)->post('/settings/credits/allot', ['user_id' => $this->agent->id, 'credits' => 50])->assertSessionHasErrors('credits');
    $this->actingAs($this->owner)->post('/settings/credits/allot', ['user_id' => $this->agent->id, 'credits' => 6])->assertRedirect();
    $this->actingAs($this->owner)->post('/settings/credits/allot', ['user_id' => $this->owner->id, 'credits' => 1])->assertNotFound();
    $this->actingAs($this->owner)->post('/settings/credits/policy', ['agents_use_pool' => false, 'agent_monthly_cap' => 25])->assertRedirect();

    $ledger = ($this->ledger)();
    expect($ledger->userSummary($this->agent->id)['available'])->toBe(6)
        ->and($ledger->poolSummary()['available'])->toBe(4)
        ->and($ledger->policy())->toBe(['agents_use_pool' => false, 'agent_monthly_cap' => 25]);
});

it('shows HQ the economics behind each action and lets HQ set prices', function (): void {
    $provider = AiProvider::factory()->create(['key' => 'primary', 'name' => 'Anthropic']);
    $model = AiModel::factory()->for($provider, 'provider')->create(['display_name' => 'Sonnet', 'input_cost_per_mtok_cents' => 300, 'output_cost_per_mtok_cents' => 1500]);
    AiFeatureRoute::query()->create(['feature_key' => 'diagnostics.ping', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.crm_ask']);
    app(ProviderRegistry::class)->fake('primary', $fake = new FakeAiProvider);
    $fake->respond('pong', in: 3_000, out: 1_000);
    app(AiGateway::class)->run(new AiTask(featureKey: 'diagnostics.ping', promptKey: 'diagnostics.ping', idempotencyKey: 'hq-1', userId: $this->owner->id));

    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hqTenant = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hqTenant, fn () => User::factory()->hqStaff()->create());
    app(TenantContext::class)->forget();
    $hq = 'http://app.rightally.io';

    $this->actingAs($staff)->get($hq.'/hq/credits')->assertInertia(fn (Assert $page) => $page->component('hq/credits')
        ->where('credit_value_microcents', 1_000_000)->where('enforced', false)
        ->where('actions', fn ($actions) => collect($actions)->firstWhere('action', 'ai.crm_ask') == [
            'action' => 'ai.crm_ask', 'label' => 'Ask your database', 'credits' => 1, 'automation' => false, 'feature' => 'diagnostics.ping',
            'model' => ['name' => 'Sonnet', 'provider' => 'Anthropic', 'input_per_mtok_cents' => 300, 'output_per_mtok_cents' => 1500],
            // 3,000 × 300 + 1,000 × 1,500 = 2,400,000 micro-cents (2.4 cents) against 1 cent of revenue.
            'uses_30d' => 1, 'avg_tokens_in' => 3000, 'avg_tokens_out' => 1000, 'avg_cost_microcents' => 2_400_000, 'revenue_microcents' => 1_000_000,
            'margin_pct' => -140, 'tokens_per_credit' => 1666,
        ])
        ->where('top_usage.0', ['tenant' => $this->tenant->name, 'credits' => 1]));

    $this->actingAs($staff)->post($hq.'/hq/credits/rates', ['action' => 'ai.crm_ask', 'credits' => 4, 'automation' => false, 'label' => 'Ask your database'])->assertRedirect();
    $this->actingAs($staff)->post($hq.'/hq/credits/value', ['dollars' => '0.02'])->assertRedirect();
    $this->actingAs($staff)->post($hq.'/hq/credits/grant', ['tenant' => $this->tenant->ulid, 'credits' => 250, 'note' => 'Welcome'])->assertRedirect();

    expect(app(RateCard::class)->rate('ai.crm_ask')['credits'])->toBe(4)
        ->and(app(RateCard::class)->creditValueMicrocents())->toBe(2_000_000)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => ($this->ledger)()->poolSummary()['available']))->toBe(249);

    $this->actingAs($this->owner)->get('http://acme.'.config('core.tenant_base_domain', 'rightally.io').'/hq/credits')->assertStatus(404);
});
