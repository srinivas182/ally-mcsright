<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Embeddings\GeminiEmbeddings;
use Modules\AI\Embeddings\OpenAiCompatibleEmbeddings;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;
use Modules\AI\Providers\AnthropicAdapter;
use Modules\AI\Providers\GeminiAdapter;
use Modules\AI\Providers\OpenAiCompatibleAdapter;

function providerRequest(): ProviderRequest
{
    return new ProviderRequest('model-x', 'System rules', 'User message', 512, '0.2', 30);
}

it('calls the Anthropic Messages API and maps the response', function (): void {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['https://api.anthropic.test/v1/messages' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Hello'], ['type' => 'text', 'text' => ' there']],
        'usage' => ['input_tokens' => 12, 'output_tokens' => 3, 'cache_read_input_tokens' => 5],
    ])]);
    $provider = AiProvider::factory()->make(['adapter' => 'anthropic', 'base_url' => 'https://api.anthropic.test', 'credential_config_key' => 'services.anthropic.key']);

    $response = app(AnthropicAdapter::class)->send($provider, providerRequest());

    expect($response->text)->toBe('Hello there')
        ->and($response->tokensIn)->toBe(12)
        ->and($response->tokensOut)->toBe(3)
        ->and($response->cacheReadTokens)->toBe(5);

    Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'test-key')
        && $r->hasHeader('anthropic-version', AnthropicAdapter::API_VERSION)
        && $r['model'] === 'model-x'
        && $r['system'] === 'System rules'
        && $r['messages'][0]['content'] === 'User message');
});

it('maps Anthropic overload and errors to the right exceptions', function (int $status, string $exception): void {
    Http::fake(['*' => Http::response(['error' => 'x'], $status)]);
    $provider = AiProvider::factory()->make(['adapter' => 'anthropic', 'base_url' => 'https://api.anthropic.test']);

    app(AnthropicAdapter::class)->send($provider, providerRequest());
})->throws(Exception::class)->with([
    'overloaded' => [529, ProviderUnavailable::class],
    'rate limited' => [429, ProviderUnavailable::class],
    'server error' => [500, ProviderUnavailable::class],
    'bad request' => [400, ProviderRejected::class],
]);

it('throws the precise exception per status', function (): void {
    $provider = AiProvider::factory()->make(['adapter' => 'anthropic', 'base_url' => 'https://api.anthropic.test']);

    Http::fake(['*' => Http::sequence()->push([], 529)->push([], 401)]);

    expect(fn () => app(AnthropicAdapter::class)->send($provider, providerRequest()))->toThrow(ProviderUnavailable::class)
        ->and(fn () => app(AnthropicAdapter::class)->send($provider, providerRequest()))->toThrow(ProviderRejected::class);
});

it('calls OpenAI-compatible chat completions and maps the response', function (): void {
    Http::fake(['https://llm.internal.test/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'Classified']]],
        'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 2],
    ])]);
    $provider = AiProvider::factory()->make(['adapter' => 'openai_compatible', 'base_url' => 'https://llm.internal.test', 'name' => 'Self-hosted']);

    $response = app(OpenAiCompatibleAdapter::class)->send($provider, providerRequest());

    expect($response->text)->toBe('Classified')
        ->and($response->tokensIn)->toBe(40)
        ->and($response->tokensOut)->toBe(2);

    Http::assertSent(fn (Request $r) => $r['messages'][0]['role'] === 'system' && $r['messages'][1]['content'] === 'User message');
});

it('treats connection failures as unavailable', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));
    $provider = AiProvider::factory()->make(['adapter' => 'openai_compatible', 'base_url' => 'https://llm.internal.test']);

    app(OpenAiCompatibleAdapter::class)->send($provider, providerRequest());
})->throws(ProviderUnavailable::class);

it('calls Gemini generateContent and maps the response', function (): void {
    Http::fake(['https://gemini.test/v1beta/models/gemini-x:generateContent' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Hi'], ['text' => ' there']]]]],
        'usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 2, 'cachedContentTokenCount' => 4],
    ])]);
    config(['services.gemini.key' => 'g-key']);
    $provider = AiProvider::factory()->make(['adapter' => 'gemini', 'base_url' => 'https://gemini.test', 'credential_config_key' => 'services.gemini.key']);

    $response = app(GeminiAdapter::class)->send($provider, new ProviderRequest('gemini-x', 'Rules', 'Question', 100, '0.1', 30));

    expect($response->text)->toBe('Hi there')->and($response->tokensIn)->toBe(9)->and($response->cacheReadTokens)->toBe(4);
    Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'g-key')
        && $r['systemInstruction']['parts'][0]['text'] === 'Rules'
        && $r['contents'][0]['parts'][0]['text'] === 'Question');
});

it('maps Gemini errors', function (): void {
    Http::fake(['*' => Http::sequence()->push([], 503)->push([], 400)]);
    $provider = AiProvider::factory()->make(['adapter' => 'gemini', 'base_url' => 'https://gemini.test']);
    $adapter = app(GeminiAdapter::class);

    expect(fn () => $adapter->send($provider, providerRequest()))->toThrow(ProviderUnavailable::class)
        ->and(fn () => $adapter->send($provider, providerRequest()))->toThrow(ProviderRejected::class);
});

it('maps OpenAI-style embeddings in input order, with either usage field', function (): void {
    Http::fake(['https://embed.test/v1/embeddings' => Http::response([
        'data' => [['index' => 1, 'embedding' => [0.3, 0.4]], ['index' => 0, 'embedding' => [0.1, 0.2]]],
        'usage' => ['total_tokens' => 7],
    ])]);
    $provider = AiProvider::factory()->make(['adapter' => 'openai_compatible', 'base_url' => 'https://embed.test']);

    $response = app(OpenAiCompatibleEmbeddings::class)->embed($provider, 'voyage-x', ['a', 'b'], 30);

    expect($response->vectors)->toBe([[0.1, 0.2], [0.3, 0.4]])->and($response->tokens)->toBe(7);
});

it('maps Gemini batch embeddings', function (): void {
    Http::fake(['https://gemini.test/v1beta/models/text-embed:batchEmbedContents' => Http::response([
        'embeddings' => [['values' => [1, 0]], ['values' => [0, 1]]],
    ])]);
    $provider = AiProvider::factory()->make(['adapter' => 'gemini', 'base_url' => 'https://gemini.test']);

    $response = app(GeminiEmbeddings::class)->embed($provider, 'text-embed', ['abcd', 'efgh'], 30);

    expect($response->vectors)->toBe([[1.0, 0.0], [0.0, 1.0]])->and($response->tokens)->toBe(2);
    Http::assertSent(fn (Request $r) => $r['requests'][1]['content']['parts'][0]['text'] === 'efgh');
});
