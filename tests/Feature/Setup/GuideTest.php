<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Compensation\Models\Plan;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Models\RoutingRule;
use Modules\Sites\Models\Site;
use Tests\Fixtures\FakeAiProvider;

/*
| SU2 (specs/setup/02): a few plain questions, or the broker's own words read by AI, and RightAlly sets itself up.
| Money is only ever a draft until the owner publishes it.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
});

it('drafts the commission plan from the answers without publishing it', function (): void {
    $this->actingAs($this->owner)->post('/setup/guide', ['pay_mode' => 'split', 'agent_percent' => 80, 'cap_dollars' => 18000, 'fee_dollars' => 395])->assertSessionHasNoErrors();

    $plan = Plan::query()->sole();
    $version = $plan->versions()->sole();
    expect($plan->name)->toBe('80/20 plan (from setup)')
        ->and($version->rules['split'])->toEqual(['mode' => 'percentage', 'agent_percent' => '80'])
        ->and($version->rules['cap']['amount_cents'])->toBe(1_800_000)
        ->and($version->rules['post_split'][0]['amount'])->toBe(39_500)
        ->and($version->published_at)->toBeNull();
    $this->actingAs($this->owner)->get('/setup/guide')->assertInertia(fn (Assert $page) => $page->component('setup/guide')
        ->where('applied.pay', fn ($t) => str_contains($t, 'check and publish'))
        ->where('readiness.steps.0', ['key' => 'plan', 'label' => 'A commission plan is published', 'done' => false, 'href' => '/compensation/plans']));
});

it('drafts a flat-fee plan', function (): void {
    $this->actingAs($this->owner)->post('/setup/guide', ['pay_mode' => 'flat'])->assertSessionHasErrors('flat_fee_dollars');
    $this->actingAs($this->owner)->post('/setup/guide', ['pay_mode' => 'flat', 'flat_fee_dollars' => 500])->assertSessionHasNoErrors();

    expect(Plan::query()->sole()->versions()->sole()->rules['split'])->toEqual(['mode' => 'flat_fee', 'bands' => [['up_to_price_cents' => null, 'fee_cents' => 50_000]]]);
});

it('sets where new leads go, waiting for agents when there are none yet', function (): void {
    $this->actingAs($this->owner)->post('/setup/guide', ['lead_routing' => 'round_robin']);
    expect(RoutingRule::query()->count())->toBe(0);

    $a = User::factory()->create();
    $b = User::factory()->create();
    $this->actingAs($this->owner)->post('/setup/guide', ['lead_routing' => 'round_robin']);
    $this->actingAs($this->owner)->post('/setup/guide', ['lead_routing' => 'round_robin']); // answered again: still one rule

    $rule = RoutingRule::query()->sole();
    expect($rule->method)->toBe('round_robin')->and(collect($rule->agent_ids)->sort()->values()->all())->toBe([$a->id, $b->id]);

    $this->actingAs($this->owner)->post('/setup/guide', ['lead_routing' => 'owner']);
    expect(RoutingRule::query()->sole()->method)->toBe('agent')->and(RoutingRule::query()->sole()->agent_ids)->toBe([$this->owner->id]);
});

it('switches on the assistant for new leads and drafts the website in the chosen style', function (): void {
    $this->actingAs($this->owner)->post('/setup/guide', ['ai_first_reply' => true, 'website_style' => 'dark'])->assertSessionHasNoErrors();

    expect(app(AiResponder::class)->settings()['enabled'])->toBeTrue()
        ->and(Site::query()->where('kind', 'brokerage')->sole()->template)->toBe('aurum')
        ->and(Site::query()->sole()->status)->not->toBe('live');
});

it('reads the broker\'s own words into answers for them to check, setting nothing up yet', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat', 'json']]);
    AiFeatureRoute::query()->create(['feature_key' => 'setup.describe', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.setup_describe']);
    app(ProviderRegistry::class)->fake($model->provider->key, $ai = new FakeAiProvider);
    $ai->respond('{"pay_mode": "split", "agent_percent": 80, "cap_dollars": 18000, "fee_dollars": 395, "lead_routing": "round_robin", "ai_first_reply": true, "website_style": "neon", "unclear": ["The website colour neon is not one of the choices."]}');

    $this->actingAs($this->owner)->post('/setup/guide/describe', ['words' => 'Agents get 80/20 with an $18k cap and a $395 fee. Round robin leads.'])->assertRedirect();
    $this->actingAs($this->owner)->get('/setup/guide')->assertInertia(fn (Assert $page) => $page->where('suggested', true)
        ->where('answers.agent_percent', 80)->where('answers.lead_routing', 'round_robin')->missing('answers.website_style')
        ->where('unclear.0', 'The website colour neon is not one of the choices.'));

    expect(Plan::query()->count())->toBe(0);
});

it('is for owners only', function (): void {
    $agent = User::factory()->create();
    $this->actingAs($agent)->get('/setup/guide')->assertForbidden();
    $this->actingAs($agent)->post('/setup/guide', ['ai_first_reply' => true])->assertForbidden();
});
