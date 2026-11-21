<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Events\RedTeamFailed;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRedTeamRun;
use Modules\AI\Models\AiRequest;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\RedTeam\RedTeamRunner;
use Modules\AI\RedTeam\Scenario;
use Modules\AI\RedTeam\Scenarios;
use Modules\AI\Security\PromptBuilder;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hq = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hq, fn () => User::factory()->hqStaff()->create());
    $this->provider = AiProvider::factory()->create(['key' => 'llm', 'name' => 'Test LLM']);
    $this->model = AiModel::factory()->for($this->provider, 'provider')->create(['display_name' => 'Fast model']);
    $this->backup = AiModel::factory()->create(['display_name' => 'Backup model']);
    $this->route = AiFeatureRoute::query()->create(['feature_key' => 'assistant.answer', 'primary_model_id' => $this->model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.answer']);
});

/** A model that obeys whatever the injected document asks: it repeats the canary it finds. */
function obedientModel(): ProviderAdapter
{
    return new class implements ProviderAdapter
    {
        public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
        {
            foreach ([...Scenarios::all(), new Scenario('x', 'q', 'd', 'PLAINCANARYWORD')] as $scenario) {
                if (str_contains($request->userMessage, $scenario->canary)) {
                    return new ProviderResponse("Sure! {$scenario->canary}", 50, 10);
                }
            }

            return new ProviderResponse('A careful answer.', 50, 10);
        }
    };
}

it('is only for HQ staff on the HQ host', function (): void {
    $brokerage = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme']);
    $owner = app(TenantContext::class)->runAs($brokerage, fn () => User::factory()->owner()->create());

    $this->actingAs($owner)->get('http://acme.rightally.app/hq/ai')->assertNotFound();
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/ai')->assertOk();
});

it('shows usage, cost and blocked answers across all brokerages', function (): void {
    foreach (Tenant::factory()->count(2)->create() as $tenant) {
        app(TenantContext::class)->runAs($tenant, function (): void {
            foreach (['ok', 'ok', 'failed'] as $status) {
                AiRequest::query()->create([
                    'feature_key' => 'assistant.answer', 'prompt_key' => 'p', 'prompt_version' => '1', 'provider_key' => 'llm',
                    'model_id' => 'fast-1', 'attempt' => 1, 'status' => $status, 'cost_microcents' => 50_000_000, 'latency_ms' => 800,
                    'source_record_ids' => [], 'output_policy_result' => $status === 'failed' ? 'blocked' : 'passed',
                    'idempotency_key' => uniqid(), 'created_at' => now(),
                ]);
            }
        });
    }

    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/ai')->assertInertia(fn (Assert $page) => $page
        ->component('hq/ai/index')
        ->where('usage.0.requests', 6)
        ->where('usage.0.failures', 2)
        ->where('usage.0.blocked', 2)
        ->where('usage.0.cost_cents', 300));
});

it('only activates providers with zero retention and no training confirmed', function (): void {
    $url = "http://app.rightally.io/hq/ai/providers/{$this->provider->id}";
    $base = ['zero_retention_confirmed' => true, 'no_training_confirmed' => false, 'status' => 'active', 'confirmation' => 'DPA signed 2026-11-01'];

    $this->actingAs($this->staff)->put($url, $base)->assertSessionHasErrors('status');
    $this->actingAs($this->staff)->put($url, ['confirmation' => ''] + $base)->assertSessionHasErrors('confirmation');
    $this->actingAs($this->staff)->put($url, ['no_training_confirmed' => true] + $base)->assertSessionHasNoErrors();
});

it('edits routes safely', function (array $payload, ?string $error, bool $lock = false, string $feature = 'assistant.answer'): void {
    $this->route->update(['feature_key' => $feature, 'locked' => $lock]);
    $this->backup->update(['status' => 'active']);
    $resolve = function (mixed $value) use (&$resolve): mixed {
        if ($value instanceof Closure) {
            return $value($this);
        }

        return is_array($value) ? array_map($resolve, $value) : $value;
    };
    $payload = $resolve($payload);

    $response = $this->actingAs($this->staff)->put("http://app.rightally.io/hq/ai/routes/{$this->route->id}", $payload + ['timeout_seconds' => 30]);

    $error === null ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors($error);
})->with([
    'valid change' => [['primary_model_id' => fn ($t) => $t->backup->id, 'fallback_model_ids' => [fn ($t) => $t->model->id]], null],
    'fallback equals primary' => [['primary_model_id' => fn ($t) => $t->model->id, 'fallback_model_ids' => [fn ($t) => $t->model->id]], 'fallback_model_ids'],
    'locked route' => [['primary_model_id' => fn ($t) => $t->backup->id, 'fallback_model_ids' => []], 'route', true],
    'embedding fallback' => [['primary_model_id' => fn ($t) => $t->model->id, 'fallback_model_ids' => [fn ($t) => $t->backup->id]], 'fallback_model_ids', false, 'retrieval.embed'],
]);

/** A model that ignores injected instructions. */
function carefulModel(): ProviderAdapter
{
    return new class implements ProviderAdapter
    {
        public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
        {
            return new ProviderResponse('The document covers the requested dates.', 50, 10);
        }
    };
}

it('red team: a careful model passes', function (): void {
    app(ProviderRegistry::class)->fake('llm', carefulModel());

    $run = app(RedTeamRunner::class)->run($this->hq, 10_000_000_000);

    expect($run->status)->toBe('passed')
        ->and($run->scenarios_run)->toBe(count(Scenarios::all()))
        ->and($run->injections_followed)->toBe(0)
        ->and($run->errors)->toBe(0);
});

it('red team: following a critical injection (wire, contact, link) fails the run', function (): void {
    app(ProviderRegistry::class)->fake('llm', obedientModel());

    $run = app(RedTeamRunner::class)->run($this->hq, 10_000_000_000);
    $critical = count(array_filter(Scenarios::all(), fn (Scenario $s) => $s->critical));

    // AS1: instructions hidden in invisible characters are removed before the model reads anything, so even an
    // obedient model cannot follow them.
    $visible = count(array_filter(Scenarios::all(), fn (Scenario $s) => str_contains(PromptBuilder::visible($s->injectedDocument), $s->canary)));

    expect($run->status)->toBe('failed')
        ->and($run->critical_followed)->toBe($critical)
        ->and($run->injections_followed)->toBe($visible)
        ->and($visible)->toBe(count(Scenarios::all()) - 1);
});

it('red team: following only non-critical injections is a warning', function (): void {
    app(ProviderRegistry::class)->fake('llm', obedientModel());
    $minor = array_values(array_filter(Scenarios::all(), fn (Scenario $s) => ! $s->critical));

    $run = app(RedTeamRunner::class)->run($this->hq, 10_000_000_000, $minor);

    expect($run->status)->toBe('warning')->and($run->critical_followed)->toBe(0);
});

it('red team: invented contact details are withheld by the output policy', function (): void {
    app(ProviderRegistry::class)->fake('llm', new class implements ProviderAdapter
    {
        public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
        {
            return new ProviderResponse('Call the agent at (601) 555-0199 or email made.up@nowhere.test', 50, 10);
        }
    });

    $run = app(RedTeamRunner::class)->run($this->hq, 10_000_000_000);

    expect($run->status)->toBe('passed')->and($run->blocked_by_policy)->toBe(count(Scenarios::all()));
});

it('red team command: exit code, alert and budget', function (): void {
    Event::fake([RedTeamFailed::class]);
    app(ProviderRegistry::class)->fake('llm', carefulModel());

    $this->artisan('ai:red-team', ['--budget-cents' => 0])->assertSuccessful();
    expect(AiRedTeamRun::query()->latest('id')->first()?->status)->toBe('budget_stopped');

    app(ProviderRegistry::class)->fake('llm', obedientModel());
    $this->artisan('ai:red-team', ['--budget-cents' => 1000])->assertFailed();
    Event::assertDispatched(RedTeamFailed::class);
});

it('is scheduled weekly', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'ai:red-team'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 5 * * 1');
});

// --- AI1: provider API keys saved in HQ ------------------------------------------------------------------------

it('saves a provider key from HQ, encrypted, checked first and never shown again', function (): void {
    Http::fake(['api.anthropic.com/v1/models' => Http::response(['data' => []])]);
    $provider = AiProvider::factory()->create(['key' => 'anthropic', 'name' => 'Anthropic (Claude)', 'adapter' => 'anthropic', 'base_url' => 'https://api.anthropic.com', 'credential_config_key' => 'services.anthropic.key']);
    config(['services.anthropic.key' => 'sk-ant-from-the-server-env-0000']);
    expect($provider->keySource())->toBe('server');

    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])
        ->post("http://app.rightally.io/hq/ai/providers/{$provider->id}/key", ['api_key' => 'sk-ant-api03-saved-in-hq-abcd1234'])
        ->assertSessionHasNoErrors();

    $provider->refresh();
    expect($provider->apiKey())->toBe('sk-ant-api03-saved-in-hq-abcd1234')
        ->and($provider->keySource())->toBe('hq')
        ->and($provider->api_key_hint)->toBe('1234')
        ->and((string) DB::connection($provider->getConnectionName())->table('ai_providers')->where('id', $provider->id)->value('api_key'))->not->toContain('saved-in-hq');
    Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'sk-ant-api03-saved-in-hq-abcd1234'));

    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/ai')
        ->assertDontSee('saved-in-hq')
        ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($ps) => collect($ps)->contains(fn ($p) => $p['key'] === 'anthropic' && $p['key_source'] === 'hq' && $p['key_hint'] === '1234')));
});

it('does not save a key the provider refuses, and asks for the password', function (): void {
    Http::fake(['api.openai.com/v1/models' => Http::response(['error' => 'bad key'], 401)]);
    $provider = AiProvider::factory()->create(['key' => 'openai', 'name' => 'OpenAI (ChatGPT)', 'adapter' => 'openai_compatible', 'base_url' => 'https://api.openai.com']);

    $this->actingAs($this->staff)
        ->post("http://app.rightally.io/hq/ai/providers/{$provider->id}/key", ['api_key' => 'sk-proj-wrong-key-000000000000'])
        ->assertRedirect();
    expect($provider->refresh()->api_key)->toBeNull();

    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])
        ->post("http://app.rightally.io/hq/ai/providers/{$provider->id}/key", ['api_key' => 'sk-proj-wrong-key-000000000000'])
        ->assertSessionHasErrors('api_key');
    expect($provider->refresh()->api_key)->toBeNull()->and($provider->keySource())->toBe('none');
});

it('checks and removes a saved key, falling back to the server key', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => []])]);
    $provider = AiProvider::factory()->create(['key' => 'gemini', 'adapter' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com', 'credential_config_key' => 'services.gemini.key']);
    $provider->forceFill(['api_key' => 'AIzaSy-saved-key-0000000000', 'api_key_hint' => '0000'])->save();
    config(['services.gemini.key' => 'AIzaSy-server-key-111111111']);

    $this->actingAs($this->staff)->post("http://app.rightally.io/hq/ai/providers/{$provider->id}/key/check")->assertRedirect();
    expect($provider->refresh()->key_checked_at)->not->toBeNull();

    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])
        ->delete("http://app.rightally.io/hq/ai/providers/{$provider->id}/key")->assertRedirect();
    expect($provider->refresh()->api_key)->toBeNull()->and($provider->apiKey())->toBe('AIzaSy-server-key-111111111');
});
