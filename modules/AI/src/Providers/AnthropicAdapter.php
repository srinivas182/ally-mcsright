<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Modules\AI\Contracts\BatchProviderAdapter;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Contracts\StreamingProviderAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;
use Modules\AI\Support\SseReader;

/**
 * Anthropic Messages API (Claude).
 */
final class AnthropicAdapter implements BatchProviderAdapter, ProviderAdapter, StreamingProviderAdapter
{
    public const API_VERSION = '2023-06-01';

    public function __construct(private readonly Http $http) {}

    public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
    {
        try {
            $response = $this->http
                ->timeout($request->timeoutSeconds)
                ->withHeaders([
                    'x-api-key' => (string) $provider->apiKey(),
                    'anthropic-version' => self::API_VERSION,
                ])
                ->acceptJson()
                ->post(rtrim($provider->base_url, '/').'/v1/messages', [
                    'model' => $request->modelId,
                    'max_tokens' => $request->maxOutputTokens,
                    'temperature' => (float) $request->temperature,
                    'system' => $request->system,
                    'messages' => [['role' => 'user', 'content' => $request->userMessage]],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("Anthropic connection failed: {$e->getMessage()}", previous: $e);
        }

        $this->guard($response);

        $text = '';
        foreach ((array) $response->json('content', []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return new ProviderResponse(
            $text,
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            (int) $response->json('usage.cache_read_input_tokens', 0),
        );
    }

    public function stream(AiProvider $provider, ProviderRequest $request, Closure $onDelta): ProviderResponse
    {
        try {
            $response = $this->http
                ->timeout($request->timeoutSeconds)
                ->withOptions(['stream' => true])
                ->withHeaders(['x-api-key' => (string) $provider->apiKey(), 'anthropic-version' => self::API_VERSION])
                ->accept('text/event-stream')
                ->post(rtrim($provider->base_url, '/').'/v1/messages', [
                    'model' => $request->modelId,
                    'max_tokens' => $request->maxOutputTokens,
                    'temperature' => (float) $request->temperature,
                    'system' => $request->system,
                    'messages' => [['role' => 'user', 'content' => $request->userMessage]],
                    'stream' => true,
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("Anthropic connection failed: {$e->getMessage()}", previous: $e);
        }

        $this->guard($response);

        $text = '';
        $in = $out = $cached = 0;
        foreach (SseReader::events($response->toPsrResponse()->getBody()) as $event) {
            $data = json_decode($event['data'], true);
            if (! is_array($data)) {
                continue;
            }

            switch ($data['type'] ?? null) {
                case 'message_start':
                    $in = (int) ($data['message']['usage']['input_tokens'] ?? 0);
                    $cached = (int) ($data['message']['usage']['cache_read_input_tokens'] ?? 0);
                    break;
                case 'content_block_delta':
                    $delta = (string) ($data['delta']['text'] ?? '');
                    if ($delta !== '') {
                        $text .= $delta;
                        $onDelta($delta);
                    }
                    break;
                case 'message_delta':
                    $out = (int) ($data['usage']['output_tokens'] ?? $out);
                    break;
                case 'error':
                    throw new ProviderUnavailable('Anthropic stream error: '.(string) ($data['error']['type'] ?? 'unknown'));
            }
        }

        return new ProviderResponse($text, $in, $out, $cached);
    }

    public function submitBatch(AiProvider $provider, array $requests): string
    {
        $payload = [];
        foreach ($requests as $customId => $request) {
            $payload[] = ['custom_id' => (string) $customId, 'params' => [
                'model' => $request->modelId,
                'max_tokens' => $request->maxOutputTokens,
                'temperature' => (float) $request->temperature,
                'system' => $request->system,
                'messages' => [['role' => 'user', 'content' => $request->userMessage]],
            ]];
        }

        try {
            $response = $this->client($provider)->post(rtrim($provider->base_url, '/').'/v1/messages/batches', ['requests' => $payload]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("Anthropic connection failed: {$e->getMessage()}", previous: $e);
        }
        $this->guard($response);

        return (string) $response->json('id');
    }

    public function fetchBatch(AiProvider $provider, string $batchId): ?array
    {
        try {
            $status = $this->client($provider)->get(rtrim($provider->base_url, '/').'/v1/messages/batches/'.rawurlencode($batchId));
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("Anthropic connection failed: {$e->getMessage()}", previous: $e);
        }
        $this->guard($status);

        if ($status->json('processing_status') !== 'ended') {
            return null;
        }

        $results = $this->client($provider)->get((string) $status->json('results_url'));
        $this->guard($results);

        $out = [];
        foreach (preg_split('/\R/', trim($results->body())) ?: [] as $line) {
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['custom_id'])) {
                continue;
            }
            $result = (array) ($row['result'] ?? []);
            if (($result['type'] ?? null) !== 'succeeded') {
                $out[(string) $row['custom_id']] = (string) ($result['type'] ?? 'errored');

                continue;
            }
            $message = (array) ($result['message'] ?? []);
            $text = '';
            foreach ((array) ($message['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }
            $usage = (array) ($message['usage'] ?? []);
            $out[(string) $row['custom_id']] = new ProviderResponse($text, (int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0), (int) ($usage['cache_read_input_tokens'] ?? 0));
        }

        return $out;
    }

    private function client(AiProvider $provider): PendingRequest
    {
        return $this->http->timeout(60)->acceptJson()->withHeaders([
            'x-api-key' => (string) $provider->apiKey(),
            'anthropic-version' => self::API_VERSION,
        ]);
    }

    private function guard(Response $response): void
    {
        $status = $response->status();

        if ($status === 429 || $status === 529 || $status >= 500) {
            throw new ProviderUnavailable("Anthropic returned HTTP {$status}.");
        }

        if ($response->failed()) {
            throw new ProviderRejected("Anthropic rejected the request (HTTP {$status}).");
        }
    }
}
