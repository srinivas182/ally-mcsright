<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\Economics;
use Modules\Billing\Credits\MarginAlert;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 18A-2a: pricing credits from real costs — model prices, planning costs, suggested credits, package margins. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
    $this->hq = 'http://app.rightally.io';

    $this->provider = AiProvider::factory()->create(['key' => 'primary', 'name' => 'Anthropic']);
    // $3 in / $15 out per million tokens, and a costlier fallback at $15 / $75.
    $this->cheap = AiModel::factory()->for($this->provider, 'provider')->create(['display_name' => 'Sonnet', 'input_cost_per_mtok_cents' => 300, 'output_cost_per_mtok_cents' => 1500]);
    $this->dear = AiModel::factory()->for($this->provider, 'provider')->create(['display_name' => 'Opus', 'input_cost_per_mtok_cents' => 1500, 'output_cost_per_mtok_cents' => 7500]);
    AiFeatureRoute::query()->create(['feature_key' => 'diagnostics.ping', 'primary_model_id' => $this->cheap->id, 'fallback_model_ids' => [$this->dear->id], 'credit_action_key' => 'ai.crm_ask']);
});

function econ(): Economics
{
    return app(Economics::class);
}

function hqAs(): mixed
{
    app(TenantContext::class)->forget();

    return test()->actingAs(test()->staff)->withSession(['auth.password_confirmed_at' => time()]);
}

it('lets HQ add providers and models with prices, keeping API keys out of the database', function (): void {
    hqAs()->post($this->hq.'/hq/ai/providers', ['preset' => 'gemini'])->assertRedirect();
    hqAs()->post($this->hq.'/hq/ai/providers', ['preset' => 'gemini'])->assertSessionHasErrors('preset');
    $gemini = AiProvider::query()->where('key', 'gemini')->sole();
    expect($gemini->status)->toBe('disabled')->and($gemini->credential_config_key)->toBe('services.gemini.key');

    hqAs()->post($this->hq.'/hq/ai/models', ['provider_id' => $gemini->id, 'model_id' => 'gemini-test-flash', 'display_name' => 'Gemini Flash', 'capabilities' => ['chat', 'json'],
        'input_per_mtok' => '0.30', 'output_per_mtok' => '2.50', 'status' => 'active'])->assertRedirect();
    $model = AiModel::query()->where('model_id', 'gemini-test-flash')->sole();
    expect($model->input_cost_per_mtok_cents)->toBe(30)->and($model->output_cost_per_mtok_cents)->toBe(250)
        ->and($model->prices_checked_at?->toDateString())->toBe('2026-06-10');

    hqAs()->put($this->hq.'/hq/ai/models/'.$model->id, ['display_name' => 'Whisper', 'capabilities' => ['transcribe'], 'input_per_mtok' => 0, 'output_per_mtok' => 0,
        'audio_per_minute' => '0.006', 'status' => 'active'])->assertRedirect();
    expect($model->fresh()->audio_cost_per_minute_microcents)->toBe(600_000);

    // A route's primary model can't be switched off.
    hqAs()->put($this->hq.'/hq/ai/models/'.$this->cheap->id, ['display_name' => 'Sonnet', 'capabilities' => ['chat'], 'input_per_mtok' => 3, 'output_per_mtok' => 15,
        'status' => 'disabled'])->assertSessionHasErrors('status');
});

it('costs speech to text per audio minute when the model is priced that way', function (): void {
    $openai = AiProvider::factory()->create(['key' => 'openai', 'adapter' => 'openai_compatible', 'base_url' => 'https://api.openai.test']);
    $whisper = AiModel::factory()->for($openai, 'provider')->create(['capabilities' => ['transcribe'], 'audio_cost_per_minute_microcents' => 600_000]);
    AiFeatureRoute::query()->create(['feature_key' => 'diagnostics.listen', 'primary_model_id' => $whisper->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.voice']);
    Http::fake(['https://api.openai.test/*' => Http::response(['text' => 'Call Maria tomorrow', 'usage' => ['type' => 'duration', 'seconds' => 90]])]);

    expect(app(AiGateway::class)->transcribe('diagnostics.listen', 'audio-bytes', 'audio/webm', 'tx-1', $this->owner->id))->toBe('Call Maria tomorrow')
        ->and(AiRequest::query()->where('feature_key', 'diagnostics.listen')->value('cost_microcents'))->toBe(900_000); // 1.5 min × $0.006
});

it('plans each action on its costliest fallback until real data exists, and suggests credits for the target margin', function (): void {
    $ask = econ()->actions()['ai.crm_ask'];
    // Estimate 2,000 in / 300 out on Opus: 2,000 × 1,500 + 300 × 7,500 = 5,250,000 micro-cents (5.25¢).
    expect($ask['estimate_microcents'])->toBe(5_250_000)->and($ask['costliest_model'])->toBe('Opus')
        ->and($ask['planning_microcents'])->toBe(5_250_000)
        // 5.25¢ ÷ (1¢ × 40%) = 13.1 → 14 credits.
        ->and($ask['suggested_credits'])->toBe(14)->and($ask['below_floor'])->toBeTrue();

    app(RateCard::class)->saveMargins(50, 30, 20, $this->staff);
    expect(app(Economics::class)->actions()['ai.crm_ask']['suggested_credits'])->toBe(11); // 5.25 ÷ 0.5 = 10.5 → 11

    expect(fn () => app(RateCard::class)->saveMargins(30, 50, 20, $this->staff))->toThrow(ValidationException::class);
});

it('uses the real 90th-percentile cost, with failed attempts spread over successes, when it is higher', function (): void {
    $route = AiFeatureRoute::query()->where('feature_key', 'diagnostics.ping')->sole();
    $route->update(['fallback_model_ids' => []]);
    app(ProviderRegistry::class)->fake('primary', $fake = new FakeAiProvider);
    foreach (range(1, 10) as $i) {
        $fake->respond('ok', in: $i === 10 ? 40_000 : 2_000, out: 300);
        app(AiGateway::class)->run(new AiTask(featureKey: 'diagnostics.ping', promptKey: 'diagnostics.ping', idempotencyKey: 'p'.$i, userId: $this->owner->id));
    }
    $ask = app(Economics::class)->actions()['ai.crm_ask'];

    // Nine uses at 1,050,000 and one at 12,450,000: the 90th percentile is the ninth (1,050,000); estimate on Sonnet is also 1,050,000.
    expect($ask['uses_30d'])->toBe(10)->and($ask['avg_cost_microcents'])->toBe(2_190_000)->and($ask['p90_cost_microcents'])->toBe(1_050_000)
        ->and($ask['planning_microcents'])->toBe(1_050_000);
});

it('applies suggested prices, all or only those below the minimum margin', function (): void {
    hqAs()->post($this->hq.'/hq/credits/rates/apply-suggested', ['only_below_floor' => true])->assertRedirect();

    $rates = app(RateCard::class)->all();
    expect($rates['ai.crm_ask']['credits'])->toBe(14)
        ->and($rates['ai.site_post']['credits'])->toBe(20); // not routed: no cost known, left alone
});

it('shows each package\'s margin for a typical customer and the worst case, and asks for a reason below the minimum', function (): void {
    DB::connection(Connections::control())->table('cost_catalog')->where('key', 'hosting.brokerage')->update(['cost_microcents' => 500_000_000]); // $5 a month
    $package = Package::query()->create(['name' => 'Growth', 'monthly_cents' => 10_000, 'yearly_cents' => 0, 'ailly_conversations' => 0, 'features' => [], 'active' => true]);

    // Only ai.crm_ask has a cost: 5,250,000 per use at 1 credit. Typical = worst = 5.25¢ per credit.
    $plan = econ()->packagePlan($package, 100);
    expect($plan)->toMatchArray(['typical_cost_microcents' => 525_000_000, 'worst_cost_microcents' => 525_000_000, 'fixed_microcents' => 500_000_000,
        'typical_margin_pct' => 89, 'below_floor' => false])
        // $100 × 20% budget ÷ 5.25¢ = 380.9 → 380.
        ->and($plan['suggested_credits'])->toBe(380);

    hqAs()->post($this->hq.'/hq/credits/packages/'.$package->ulid, ['included_credits' => 1500])->assertSessionHasErrors('reason'); // 1500 × 5.25¢ = $78.75 → 16%
    hqAs()->post($this->hq.'/hq/credits/packages/'.$package->ulid, ['included_credits' => 1500, 'reason' => 'Launch promotion for first 10 brokerages'])->assertRedirect();
    expect($package->fresh()->getAttribute('included_credits'))->toBe(1500)
        ->and($package->fresh()->getAttribute('margin_override_reason'))->toBe('Launch promotion for first 10 brokerages');

    hqAs()->get($this->hq.'/hq/credits')->assertInertia(fn (Assert $page) => $page->where('packages.0.typical_margin_pct', 16)
        ->where('typical_cost_per_credit', 5_250_000)->has('catalog', 13)->where('margins', ['target' => 60, 'floor' => 40, 'usage_budget' => 20, 'maps_alert_views' => 60_000]));
});

it('goes live in one step: suggested credits for packages without any, then enforcement', function (): void {
    $package = Package::query()->create(['name' => 'Growth', 'monthly_cents' => 10_000, 'yearly_cents' => 0, 'ailly_conversations' => 0, 'features' => [], 'active' => true]);

    hqAs()->post($this->hq.'/hq/credits/enforce', ['enforce' => true])->assertSessionHasErrors('enforce');
    expect(app(RateCard::class)->enforced())->toBeFalse();

    hqAs()->post($this->hq.'/hq/credits/go-live')->assertRedirect()->assertSessionHasNoErrors();
    expect($package->fresh()->getAttribute('included_credits'))->toBe(380)->and(app(RateCard::class)->enforced())->toBeTrue();
});

it('refuses to go live when it cannot price credits', function (): void {
    AiFeatureRoute::query()->delete();
    Package::query()->create(['name' => 'Growth', 'monthly_cents' => 10_000, 'yearly_cents' => 0, 'ailly_conversations' => 0, 'features' => [], 'active' => true]);

    hqAs()->post($this->hq.'/hq/credits/go-live')->assertSessionHasErrors('enforce');
    expect(app(RateCard::class)->enforced())->toBeFalse();
});

it('emails HQ when a price falls below the minimum margin on real costs', function (): void {
    app(ProviderRegistry::class)->fake('primary', $fake = new FakeAiProvider);
    $fake->respond('ok', in: 3_000, out: 1_000); // 2.4¢ for a 1-credit (1¢) action
    app(AiGateway::class)->run(new AiTask(featureKey: 'diagnostics.ping', promptKey: 'diagnostics.ping', idempotencyKey: 'w1', userId: $this->owner->id));

    app(TenantContext::class)->forget();
    $this->artisan('credits:margin-watch')->assertSuccessful();
    Notification::assertSentTo($this->staff, MarginAlert::class, fn (MarginAlert $n): bool => $n->alerts[0]['action'] === 'ai.crm_ask'
        && $n->alerts[0]['margin_pct'] === -140 && $n->alerts[0]['suggested_credits'] === 6);

    app(RateCard::class)->saveRate('ai.crm_ask', 6, false, 'Ask your database', $this->staff);
    Notification::fake();
    $this->artisan('credits:margin-watch')->assertSuccessful();
    Notification::assertNothingSent();
});

it('lets HQ confirm other costs (texts, calls, maps, email, hosting)', function (): void {
    hqAs()->post($this->hq.'/hq/credits/costs/twilio.sms_segment', ['cost' => '0.012', 'free_units_monthly' => 0])->assertRedirect();
    hqAs()->post($this->hq.'/hq/credits/costs/nope.item', ['cost' => '1'])->assertNotFound();

    expect(DB::connection(Connections::control())->table('cost_catalog')->where('key', 'twilio.sms_segment')->value('cost_microcents'))->toBe(1_200_000);
});
