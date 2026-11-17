<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\AiCostSpike;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Providers\AnthropicAdapter;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\FakeAiProvider;

/*
| AQ2 (specs/ai/08): a lighter model for short requests, reused answers, Anthropic prompt caching, and HQ's view of
| what AI costs by brokerage and feature, with an email when a brokerage's cost jumps.
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant);
    $this->mainProvider = AiProvider::factory()->create(['key' => 'main']);
    $this->lightProvider = AiProvider::factory()->create(['key' => 'light']);
    $this->main = AiModel::factory()->for($this->mainProvider, 'provider')->create(['model_id' => 'big-model', 'input_cost_per_mtok_cents' => 300, 'output_cost_per_mtok_cents' => 1500]);
    $this->light = AiModel::factory()->for($this->lightProvider, 'provider')->create(['model_id' => 'small-model', 'input_cost_per_mtok_cents' => 25, 'output_cost_per_mtok_cents' => 125]);
    $this->route = AiFeatureRoute::query()->create(['feature_key' => 'diagnostics.ping', 'primary_model_id' => $this->main->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.diagnostics']);
    app(ProviderRegistry::class)->fake('main', $this->big = new FakeAiProvider);
    app(ProviderRegistry::class)->fake('light', $this->small = new FakeAiProvider);
});

function aqTask(string $question, string $key): AiTask
{
    return new AiTask('diagnostics.ping', 'diagnostics.ping', $key, inputs: ['question' => $question]);
}

it('sends short requests to the lighter model first and long ones to the usual model', function (): void {
    $this->route->update(['light_model_id' => $this->light->id, 'light_max_chars' => 3000]);
    $this->small->respond('short answer');
    $this->big->respond('long answer');

    expect(app(AiGateway::class)->run(aqTask('Hi?', 'a'))->modelId)->toBe('small-model')
        ->and(app(AiGateway::class)->run(aqTask(str_repeat('Tell me more. ', 300), 'b'))->modelId)->toBe('big-model');
});

it('falls back to the usual model when the lighter one is down', function (): void {
    $this->route->update(['light_model_id' => $this->light->id, 'light_max_chars' => 3000]);
    $this->small->fail(new ProviderUnavailable('down'));
    $this->big->respond('answer');

    expect(app(AiGateway::class)->run(aqTask('Hi?', 'c'))->modelId)->toBe('big-model');
});

it('reuses an identical answer within the window, free, and never across brokerages', function (): void {
    $this->route->update(['cache_minutes' => 60]);
    $this->big->respond('first answer')->respond('other brokerage answer');

    $one = app(AiGateway::class)->run(aqTask('Same question', 'k1'));
    $two = app(AiGateway::class)->run(aqTask('Same question', 'k2'));

    expect($two->text)->toBe('first answer')->and($two->costMicrocents)->toBe(0)->and($this->big->requests)->toHaveCount(1)
        ->and(AiRequest::query()->where('idempotency_key', 'k2')->value('status'))->toBe('cached');

    $other = Tenant::factory()->create();
    $answer = app(TenantContext::class)->runAs($other, fn () => app(AiGateway::class)->run(aqTask('Same question', 'k3'))->text);
    expect($answer)->toBe('other brokerage answer')->and($one->text)->toBe('first answer');
});

it('marks long instructions for Anthropic prompt caching and counts cache writes as input', function (): void {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['https://api.anthropic.test/v1/messages' => Http::response([
        'content' => [['type' => 'text', 'text' => 'ok']],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 5, 'cache_creation_input_tokens' => 2000, 'cache_read_input_tokens' => 0],
    ])]);
    $provider = AiProvider::factory()->make(['adapter' => 'anthropic', 'base_url' => 'https://api.anthropic.test', 'credential_config_key' => 'services.anthropic.key']);

    $response = app(AnthropicAdapter::class)->send($provider, new ProviderRequest('m', str_repeat('Rule. ', 800), 'Q', 100, '0', 30));

    expect($response->tokensIn)->toBe(2600);
    Http::assertSent(fn (Request $r) => $r['system'][0]['cache_control'] === ['type' => 'ephemeral']);
    expect(AnthropicAdapter::system('Short'))->toBe('Short');
});

it('shows HQ what AI costs by brokerage and feature, and lets HQ set cost settings', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
    $acme = Tenant::factory()->create(['name' => 'Acme Realty']);
    foreach ([[$acme, 'crm.reply_draft', 'ok', 4_000_000], [$acme, 'crm.reply_draft', 'cached', 0], [$this->tenant, 'sites.ailly', 'ok', 1_000_000]] as [$t, $f, $status, $cost]) {
        DB::table('ai_requests')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $t->id, 'feature_key' => $f, 'prompt_key' => $f, 'prompt_version' => '1', 'attempt' => 1,
            'status' => $status, 'cost_microcents' => $cost, 'source_record_ids' => '[]', 'idempotency_key' => (string) Str::ulid(), 'created_at' => now()]);
    }

    $this->actingAs($staff)->get('http://app.rightally.io/hq/ai/cost')->assertInertia(fn (Assert $page) => $page->component('hq/ai/cost')
        ->where('summary.cost_cents', 5)->where('summary.reused', 1)->where('summary.saved_cents', 4)->where('summary.requests', 3)
        ->where('brokerages.0.tenant', 'Acme Realty')->where('brokerages.0.cost_cents', 4)
        ->where('features.0.feature', 'crm.reply_draft')->where('features.0.reused_pct', 50));

    $url = 'http://app.rightally.io/hq/ai/cost/routes/'.$this->route->id;
    $this->actingAs($staff)->put($url, ['light_model_id' => 99999, 'light_max_chars' => 2000, 'cache_minutes' => 30])->assertSessionHasErrors('light_model_id');
    $this->actingAs($staff)->put($url, ['light_model_id' => $this->light->id, 'light_max_chars' => 2000, 'cache_minutes' => 30])->assertSessionHasNoErrors();
    expect($this->route->fresh()?->getAttribute('cache_minutes'))->toBe(30);
});

it('emails HQ when a brokerage\'s AI cost jumps', function (): void {
    Notification::fake();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
    $acme = Tenant::factory()->create(['name' => 'Acme Realty']);
    $add = fn (CarbonImmutable $at, int $cost) => DB::table('ai_requests')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $acme->id, 'feature_key' => 'crm.reply_draft',
        'prompt_key' => 'x', 'prompt_version' => '1', 'attempt' => 1, 'status' => 'ok', 'cost_microcents' => $cost, 'source_record_ids' => '[]', 'idempotency_key' => (string) Str::ulid(), 'created_at' => $at]);
    for ($d = 2; $d <= 15; $d++) {
        $add(CarbonImmutable::today()->subDays($d)->addHours(10), 100_000_000); // $1 a day
    }
    $add(CarbonImmutable::yesterday()->addHours(10), 900_000_000); // $9 yesterday

    $this->artisan('ai:cost-watch')->assertSuccessful();

    Notification::assertSentTo($staff, AiCostSpike::class, fn (AiCostSpike $n) => $n->spikes[0]['tenant'] === 'Acme Realty' && $n->spikes[0]['yesterday_cents'] === 900);
});
