<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\AI\AiGateway;
use Modules\AI\Contracts\BatchProviderAdapter;
use Modules\AI\Contracts\CreditMeter;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Events\AiBatchCompleted;
use Modules\AI\Events\AiOutputWasBlocked;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiBatch;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Providers\AnthropicAdapter;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\FakeAiProvider;

/** A provider with a batch API: accepts a job, reports processing, then returns results. */
final class FakeBatchProvider implements BatchProviderAdapter, ProviderAdapter
{
    /** @var array<string, ProviderRequest> */
    public array $submitted = [];

    /** @var list<array<string, ProviderResponse|string>|null> */
    public array $polls = [];

    public bool $failSubmit = false;

    public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
    {
        throw new LogicException('Batch tests should not call send().');
    }

    public function submitBatch(AiProvider $provider, array $requests): string
    {
        if ($this->failSubmit) {
            throw new ProviderUnavailable('down');
        }
        $this->submitted = $requests;

        return 'batch_123';
    }

    public function fetchBatch(AiProvider $provider, string $batchId): ?array
    {
        return array_shift($this->polls);
    }
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant);
    $this->model = AiModel::factory()->create(['input_cost_per_mtok_cents' => 1000, 'output_cost_per_mtok_cents' => 1000]);
    AiFeatureRoute::query()->create(['feature_key' => 'diagnostics.ping', 'primary_model_id' => $this->model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'x']);
    $this->batchApi = new FakeBatchProvider;
    app(ProviderRegistry::class)->fake($this->model->provider->key, $this->batchApi);
    $this->credits = new class implements CreditMeter
    {
        public int $charges = 0;

        public int $refunds = 0;

        public function charge(string $actionKey, ?int $userId, string $idempotencyKey): void
        {
            $this->charges++;
        }

        public function refund(string $idempotencyKey, string $reason): void
        {
            $this->refunds++;
        }
    };
    app()->instance(CreditMeter::class, $this->credits);
});

function tasks(int $n): array
{
    return array_map(fn (int $i) => new AiTask('diagnostics.ping', 'diagnostics.ping', "job-{$i}"), range(1, $n));
}

it('submits a batch, charging each task once', function (): void {
    $batch = app(AiGateway::class)->batch(tasks(3));

    expect($batch->mode)->toBe('provider_batch')->and($batch->external_id)->toBe('batch_123')
        ->and($batch->status)->toBe('submitted')->and($this->batchApi->submitted)->toHaveCount(3)
        ->and($this->credits->charges)->toBe(3);
});

it('collects results: checked, half price, encrypted, and withheld or failed ones refunded', function (): void {
    Event::fake([AiBatchCompleted::class, AiOutputWasBlocked::class]);
    $batch = app(AiGateway::class)->batch(tasks(3));
    $this->batchApi->polls = [null, [
        't0' => new ProviderResponse('Market summary: prices rose 2% this month.', 1_000_000, 0),
        't1' => new ProviderResponse('Email the seller at hidden.person@elsewhere.test', 10, 10),
        't2' => 'errored',
    ]];

    expect(app(AiGateway::class)->collectBatch($batch))->toBeFalse()
        ->and($batch->fresh()?->status)->toBe('submitted');

    expect(app(AiGateway::class)->collectBatch($batch->fresh()))->toBeTrue();
    $batch = $batch->fresh();

    expect($batch->status)->toBe('completed')->and($batch->succeeded)->toBe(1)->and($batch->failed_count)->toBe(2)
        ->and($batch->resultFor('job-1'))->toBe('Market summary: prices rose 2% this month.')
        ->and($batch->resultFor('job-2'))->toBeNull()
        ->and($this->credits->refunds)->toBe(2)
        ->and(AiRequest::query()->where('idempotency_key', 'job-1')->value('cost_microcents'))->toBe(500_000_000)
        ->and(DB::table('ai_batch_items')->where('idempotency_key', 'job-1')->value('result'))->not->toContain('Market summary')
        ->and(AiRequest::query()->get()->toJson())->not->toContain('Market summary');
    Event::assertDispatched(AiOutputWasBlocked::class);
    Event::assertDispatched(AiBatchCompleted::class);
});

it('refunds every task and marks the batch failed when submission fails', function (): void {
    $this->batchApi->failSubmit = true;

    expect(fn () => app(AiGateway::class)->batch(tasks(2)))->toThrow(ProviderUnavailable::class)
        ->and($this->credits->refunds)->toBe(2)
        ->and(AiBatch::query()->sole()->status)->toBe('failed');
});

it('runs immediately at normal price for providers without a batch API', function (): void {
    $fake = new FakeAiProvider;
    $fake->respond('first answer')->respond('second answer');
    app(ProviderRegistry::class)->fake($this->model->provider->key, $fake);

    $batch = app(AiGateway::class)->batch(tasks(2));

    expect($batch->mode)->toBe('immediate')->and($batch->status)->toBe('completed')
        ->and($batch->resultFor('job-2'))->toBe('second answer');
});

it('holds plain-text tasks for one feature only', function (array $tasks): void {
    app(AiGateway::class)->batch($tasks);
})->throws(InvalidArgumentException::class)->with([
    'mixed features' => [[new AiTask('diagnostics.ping', 'diagnostics.ping', 'a'), new AiTask('other.feature', 'diagnostics.ping', 'b')]],
    'structured' => [[new AiTask('diagnostics.ping', 'diagnostics.ping', 'a', outputSchema: ['type' => 'object'])]],
    'empty' => [[]],
]);

it('collects batches for every brokerage on schedule and purges stored answers after 7 days', function (): void {
    $batch = app(AiGateway::class)->batch(tasks(1));
    app(TenantContext::class)->forget();
    $this->batchApi->polls = [['t0' => new ProviderResponse('Done.', 10, 10)]];

    $this->artisan('ai:batches:collect')->assertSuccessful();
    $result = app(TenantContext::class)->runAs($this->tenant, fn () => $batch->fresh()?->resultFor('job-1'));
    expect($result)->toBe('Done.');

    $this->travel(8)->days();
    $this->artisan('ai:batches:collect')->assertSuccessful();
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => $batch->fresh()?->resultFor('job-1')))->toBeNull();
});

it('uses the Anthropic Message Batches API', function (): void {
    Http::fake([
        'https://api.test/v1/messages/batches' => Http::response(['id' => 'msgbatch_1']),
        'https://api.test/v1/messages/batches/msgbatch_1' => Http::sequence()
            ->push(['processing_status' => 'in_progress'])
            ->push(['processing_status' => 'ended', 'results_url' => 'https://api.test/results/msgbatch_1']),
        'https://api.test/results/msgbatch_1' => Http::response(implode("\n", [
            json_encode(['custom_id' => 't0', 'result' => ['type' => 'succeeded', 'message' => ['content' => [['type' => 'text', 'text' => 'Hi']], 'usage' => ['input_tokens' => 4, 'output_tokens' => 1]]]]),
            json_encode(['custom_id' => 't1', 'result' => ['type' => 'expired']]),
        ])),
    ]);
    $provider = AiProvider::factory()->make(['base_url' => 'https://api.test']);
    $adapter = app(AnthropicAdapter::class);

    $id = $adapter->submitBatch($provider, ['t0' => new ProviderRequest('m', 's', 'u', 50, '0.2', 30), 't1' => new ProviderRequest('m', 's', 'u2', 50, '0.2', 30)]);
    $first = $adapter->fetchBatch($provider, $id);
    $second = $adapter->fetchBatch($provider, $id);

    expect($id)->toBe('msgbatch_1')->and($first)->toBeNull()
        ->and($second['t0'])->toBeInstanceOf(ProviderResponse::class)->and($second['t0']->text)->toBe('Hi')
        ->and($second['t1'])->toBe('expired');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.test/v1/messages/batches' && $r['requests'][1]['custom_id'] === 't1');
});
