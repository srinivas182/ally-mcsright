<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Contracts\StreamingProviderAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;
use Modules\AI\Support\SseReader;

/**
 * Google Gemini generateContent API.
 */
final class GeminiAdapter implements ProviderAdapter, StreamingProviderAdapter
{
    public function __construct(private readonly Http $http) {}

    public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
    {
        try {
            $response = $this->http
                ->timeout($request->timeoutSeconds)
                ->withHeaders(['x-goog-api-key' => (string) $provider->apiKey()])
                ->acceptJson()
                ->post(rtrim($provider->base_url, '/').'/v1beta/models/'.rawurlencode($request->modelId).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $request->system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $request->userMessage]]]],
                    'generationConfig' => [
                        'maxOutputTokens' => $request->maxOutputTokens,
                        'temperature' => (float) $request->temperature,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("Gemini connection failed: {$e->getMessage()}", previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ProviderUnavailable("Gemini returned HTTP {$status}.");
        }
        if ($response->failed()) {
            throw new ProviderRejected("Gemini rejected the request (HTTP {$status}).");
        }

        $text = '';
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            if (is_array($part) && isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        return new ProviderResponse(
            $text,
            (int) $response->json('usageMetadata.promptTokenCount', 0),
            (int) $response->json('usageMetadata.candidatesTokenCount', 0),
            (int) $response->json('usageMetadata.cachedContentTokenCount', 0),
        );
    }

    public function stream(AiProvider $provider, ProviderRequest $request, Closure $onDelta): ProviderResponse
    {
        try {
            $response = $this->http
                ->timeout($request->timeoutSeconds)
                ->withOptions(['stream' => true])
                ->withHeaders(['x-goog-api-key' => (string) $provider->apiKey()])
                ->accept('text/event-stream')
                ->post(rtrim($provider->base_url, '/').'/v1beta/models/'.rawurlencode($request->modelId).':streamGenerateContent?alt=sse', [
                    'systemInstruction' => ['parts' => [['text' => $request->system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $request->userMessage]]]],
                    'generationConfig' => ['maxOutputTokens' => $request->maxOutputTokens, 'temperature' => (float) $request->temperature],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("Gemini connection failed: {$e->getMessage()}", previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ProviderUnavailable("Gemini returned HTTP {$status}.");
        }
        if ($response->failed()) {
            throw new ProviderRejected("Gemini rejected the request (HTTP {$status}).");
        }

        $text = '';
        $in = $out = $cached = 0;
        foreach (SseReader::events($response->toPsrResponse()->getBody()) as $event) {
            $data = json_decode($event['data'], true);
            if (! is_array($data)) {
                continue;
            }
            foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
                $delta = is_array($part) ? (string) ($part['text'] ?? '') : '';
                if ($delta !== '') {
                    $text .= $delta;
                    $onDelta($delta);
                }
            }
            if (isset($data['usageMetadata']) && is_array($data['usageMetadata'])) {
                $in = (int) ($data['usageMetadata']['promptTokenCount'] ?? $in);
                $out = (int) ($data['usageMetadata']['candidatesTokenCount'] ?? $out);
                $cached = (int) ($data['usageMetadata']['cachedContentTokenCount'] ?? $cached);
            }
        }

        return new ProviderResponse($text, $in, $out, $cached);
    }
}
