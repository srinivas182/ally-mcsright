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
 * OpenAI-style Chat Completions API: OpenAI and self-hosted open-source models served
 * through an OpenAI-compatible endpoint.
 */
final class OpenAiCompatibleAdapter implements ProviderAdapter
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
}
