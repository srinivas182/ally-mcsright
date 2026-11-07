<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;

/**
 * Anthropic Messages API (Claude).
 */
final class AnthropicAdapter implements ProviderAdapter
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
