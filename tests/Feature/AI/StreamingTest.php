<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\AI\AiGateway;
use Modules\AI\Contracts\CreditMeter;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Exceptions\AiOutputBlocked;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Http\AiEventStream;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Providers\AnthropicAdapter;
use Modules\AI\Providers\GeminiAdapter;
use Modules\AI\Providers\OpenAiCompatibleAdapter;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\Security\OutputPolicy;
use Modules\AI\Security\StreamGuard;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    app(TenantContext::class)->set(Tenant::factory()->create());
    $this->primary = AiModel::factory()->create();
    $this->backup = AiModel::factory()->create();
    AiFeatureRoute::query()->create(['feature_key' => 'diagnostics.ping', 'primary_model_id' => $this->primary->id, 'fallback_model_ids' => [$this->backup->id], 'credit_action_key' => 'x']);
    $this->main = new FakeAiProvider;
    $this->fallback = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($this->primary->provider->key, $this->main);
    app(ProviderRegistry::class)->fake($this->backup->provider->key, $this->fallback);
    $this->credits = new class implements CreditMeter
    {
        public int $refunds = 0;

        public function charge(string $actionKey, ?int $userId, string $idempotencyKey): void {}

        public function refund(string $idempotencyKey, string $reason): void
        {
            $this->refunds++;
        }
    };
    app()->instance(CreditMeter::class, $this->credits);
});

function streamTask(string $key = 'stream-1'): AiTask
{
    return new AiTask('diagnostics.ping', 'diagnostics.ping', $key);
}

it('streams the answer in checked pieces that add up to the whole answer', function (): void {
    $answer = 'The inspection period ends on Friday the 14th, so book the inspector this week and tell the buyer.';
    $this->main->respond($answer);
    $pieces = [];

    $result = app(AiGateway::class)->stream(streamTask(), function (string $text) use (&$pieces): void {
        $pieces[] = $text;
    });

    expect(implode('', $pieces))->toBe($answer)
        ->and(count($pieces))->toBeGreaterThan(1)
        ->and($result->text)->toBe($answer)
        ->and(AiRequest::query()->sole()->status)->toBe('ok');
});

it('withholds the answer when it mentions an unpermitted contact, and never shows it half-written', function (): void {
    $this->main->chunkSize = 3;
    $this->main->respond('Sure, here is what you need to know about the deal. Call the other agent at (601) 555-0199 today.');
    $shown = '';

    expect(fn () => app(AiGateway::class)->stream(streamTask(), function (string $text) use (&$shown): void {
        $shown .= $text;
    }))->toThrow(AiOutputBlocked::class);

    expect($shown)->not->toContain('601')->not->toContain('555')
        ->and($this->credits->refunds)->toBe(1)
        ->and(AiRequest::query()->sole()->status)->toBe('blocked');
});

it('falls back to another model while nothing has been shown', function (): void {
    $this->main->fail(new ProviderUnavailable('down'));
    $this->fallback->respond('An answer from the backup model that is long enough to stream in pieces.');
    $shown = '';

    $result = app(AiGateway::class)->stream(streamTask(), function (string $t) use (&$shown): void {
        $shown .= $t;
    });

    expect($result->modelId)->toBe($this->backup->model_id)->and($shown)->toContain('backup model');
});

it('does not switch models once text has been shown', function (): void {
    $this->main->chunkSize = 60;
    $this->main->failMidStream = new ProviderUnavailable('dropped');
    $this->main->respond(str_repeat('word ', 40));
    $this->fallback->respond('should never be used');

    expect(fn () => app(AiGateway::class)->stream(streamTask(), fn () => null))->toThrow(ProviderUnavailable::class)
        ->and($this->fallback->requests)->toBe([])
        ->and($this->credits->refunds)->toBe(1);
});

it('refuses to stream structured answers', function (): void {
    app(AiGateway::class)->stream(new AiTask('diagnostics.ping', 'diagnostics.ping', 'k', outputSchema: ['type' => 'object']), fn () => null);
})->throws(InvalidArgumentException::class);

it('holds back the last characters until the answer is complete', function (): void {
    $guard = new StreamGuard(new OutputPolicy, '');

    expect($guard->push('Short answer.'))->toBe('')
        ->and($guard->push(' '.str_repeat('More words here ', 4)))->not->toBe('')
        ->and(mb_strlen($guard->received()))->toBeGreaterThan(StreamGuard::HOLD_BACK)
        ->and($guard->finish())->not->toBe('');
});

it('sends browser events: text, then done or withheld', function (): void {
    // The stream flushes its buffer as it goes (as a live stream must), so capture one level up.
    $capture = function (Closure $send): string {
        ob_start();
        ob_start();
        $send();
        ob_end_flush();

        return (string) ob_get_clean();
    };

    $this->main->respond('A friendly answer that is long enough to arrive in more than one piece of text.');
    $ok = $capture(fn () => AiEventStream::response(fn ($emit) => app(AiGateway::class)->stream(streamTask('e1'), $emit))->sendContent());

    $this->main->respond('Email the seller at secret.person@elsewhere.test right away please, it is urgent.');
    $blocked = $capture(fn () => AiEventStream::response(fn ($emit) => app(AiGateway::class)->stream(streamTask('e2'), $emit))->sendContent());

    expect($ok)->toContain('event: text')->toContain('event: done')
        ->and($blocked)->toContain('event: withheld')->not->toContain('secret.person');
});

function sse(array $events): string
{
    return implode('', array_map(fn ($e) => (is_string($e) ? "data: {$e}" : 'data: '.json_encode($e))."\n\n", $events));
}

it('streams from Anthropic, OpenAI-compatible and Gemini APIs', function (string $adapter, array $events, int $in, int $out): void {
    Http::fake(['*' => Http::response(sse($events), 200, ['Content-Type' => 'text/event-stream'])]);
    $provider = AiProvider::factory()->make(['base_url' => 'https://api.test']);
    $pieces = [];

    $response = app($adapter)->stream($provider, new ProviderRequest('m', 'sys', 'hi', 100, '0.2', 30), function (string $d) use (&$pieces): void {
        $pieces[] = $d;
    });

    expect($pieces)->toBe(['Hello', ' world'])->and($response->text)->toBe('Hello world')
        ->and($response->tokensIn)->toBe($in)->and($response->tokensOut)->toBe($out);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.test'));
})->with([
    'anthropic' => [AnthropicAdapter::class, [
        ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12]]],
        ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
        ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => ' world']],
        ['type' => 'message_delta', 'usage' => ['output_tokens' => 3]],
    ], 12, 3],
    'openai' => [OpenAiCompatibleAdapter::class, [
        ['choices' => [['delta' => ['content' => 'Hello']]]],
        ['choices' => [['delta' => ['content' => ' world']]]],
        ['choices' => [], 'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 2]],
        '[DONE]',
    ], 9, 2],
    'gemini' => [GeminiAdapter::class, [
        ['candidates' => [['content' => ['parts' => [['text' => 'Hello']]]]]],
        ['candidates' => [['content' => ['parts' => [['text' => ' world']]]]], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2]],
    ], 5, 2],
]);
