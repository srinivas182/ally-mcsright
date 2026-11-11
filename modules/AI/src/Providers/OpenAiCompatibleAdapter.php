<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Contracts\StreamingProviderAdapter;
use Modules\AI\Contracts\TranscriptionAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;
use Modules\AI\Support\SseReader;

/**
 * OpenAI-style Chat Completions API: OpenAI and self-hosted open-source models served
 * through an OpenAI-compatible endpoint.
 */
final class OpenAiCompatibleAdapter implements ProviderAdapter, StreamingProviderAdapter, TranscriptionAdapter
{
    public function __construct(private readonly Http $http) {}

    public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
    {
        $client = $this->http->timeout($request->timeoutSeconds)->acceptJson();

        $key = $provider->apiKey();
        if ($key !== null) {
            $client = $client->withToken($key);
        }

        try {
            $response = $client->post(rtrim($provider->base_url, '/').'/v1/chat/completions', [
                'model' => $request->modelId,
                'max_tokens' => $request->maxOutputTokens,
                'temperature' => (float) $request->temperature,
                'messages' => [
                    ['role' => 'system', 'content' => $request->system],
                    ['role' => 'user', 'content' => $request->userMessage],
                ],
            ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("{$provider->name} connection failed: {$e->getMessage()}", previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ProviderUnavailable("{$provider->name} returned HTTP {$status}.");
        }
        if ($response->failed()) {
            throw new ProviderRejected("{$provider->name} rejected the request (HTTP {$status}).");
        }

        return new ProviderResponse(
            (string) $response->json('choices.0.message.content', ''),
            (int) $response->json('usage.prompt_tokens', 0),
            (int) $response->json('usage.completion_tokens', 0),
            (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0),
        );
    }

    public function stream(AiProvider $provider, ProviderRequest $request, Closure $onDelta): ProviderResponse
    {
        $client = $this->http->timeout($request->timeoutSeconds)->withOptions(['stream' => true])->accept('text/event-stream');
        $key = $provider->apiKey();
        if ($key !== null) {
            $client = $client->withToken($key);
        }

        try {
            $response = $client->post(rtrim($provider->base_url, '/').'/v1/chat/completions', [
                'model' => $request->modelId,
                'max_tokens' => $request->maxOutputTokens,
                'temperature' => (float) $request->temperature,
                'messages' => [
                    ['role' => 'system', 'content' => $request->system],
                    ['role' => 'user', 'content' => $request->userMessage],
                ],
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("{$provider->name} connection failed: {$e->getMessage()}", previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ProviderUnavailable("{$provider->name} returned HTTP {$status}.");
        }
        if ($response->failed()) {
            throw new ProviderRejected("{$provider->name} rejected the request (HTTP {$status}).");
        }

        $text = '';
        $in = $out = $cached = 0;
        foreach (SseReader::events($response->toPsrResponse()->getBody()) as $event) {
            if ($event['data'] === '[DONE]') {
                break;
            }
            $data = json_decode($event['data'], true);
            if (! is_array($data)) {
                continue;
            }
            $delta = (string) ($data['choices'][0]['delta']['content'] ?? '');
            if ($delta !== '') {
                $text .= $delta;
                $onDelta($delta);
            }
            if (isset($data['usage']) && is_array($data['usage'])) {
                $in = (int) ($data['usage']['prompt_tokens'] ?? 0);
                $out = (int) ($data['usage']['completion_tokens'] ?? 0);
                $cached = (int) ($data['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
            }
        }

        return new ProviderResponse($text, $in, $out, $cached);
    }

    public function transcribe(AiProvider $provider, string $modelId, string $audio, string $mimeType, ?string $language, int $timeoutSeconds): ProviderResponse
    {
        $client = $this->http->timeout($timeoutSeconds)->acceptJson();
        $key = $provider->apiKey();
        if ($key !== null) {
            $client = $client->withToken($key);
        }

        $extension = match (true) {
            str_contains($mimeType, 'webm') => 'webm',
            str_contains($mimeType, 'mp4'), str_contains($mimeType, 'm4a') => 'm4a',
            str_contains($mimeType, 'mpeg') => 'mp3',
            str_contains($mimeType, 'wav') => 'wav',
            str_contains($mimeType, 'ogg') => 'ogg',
            default => 'webm',
        };

        try {
            $response = $client->attach('file', $audio, 'speech.'.$extension, ['Content-Type' => $mimeType])
                ->post(rtrim($provider->base_url, '/').'/v1/audio/transcriptions', array_filter([
                    'model' => $modelId,
                    'language' => $language,
                    'response_format' => 'json',
                ]));
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("{$provider->name} connection failed: {$e->getMessage()}", previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ProviderUnavailable("{$provider->name} returned HTTP {$status}.");
        }
        if ($response->failed()) {
            throw new ProviderRejected("{$provider->name} rejected the audio (HTTP {$status}).");
        }

        return new ProviderResponse(
            trim((string) $response->json('text')),
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
        );
    }
}
