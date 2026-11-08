<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;

/**
 * Google Gemini generateContent API.
 */
final class GeminiAdapter implements ProviderAdapter
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
}
