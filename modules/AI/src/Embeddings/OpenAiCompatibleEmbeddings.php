<?php

declare(strict_types=1);

namespace Modules\AI\Embeddings;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Modules\AI\Contracts\EmbeddingAdapter;
use Modules\AI\Data\EmbeddingResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;

/**
 * OpenAI-style /v1/embeddings (OpenAI, Voyage AI, self-hosted OpenAI-compatible servers).
 */
final class OpenAiCompatibleEmbeddings implements EmbeddingAdapter
{
    public function __construct(private readonly Http $http) {}

    public function embed(AiProvider $provider, string $modelId, array $texts, int $timeoutSeconds): EmbeddingResponse
    {
        $client = $this->http->timeout($timeoutSeconds)->acceptJson();
        $key = $provider->apiKey();
        if ($key !== null) {
            $client = $client->withToken($key);
        }

        try {
            $response = $client->post(rtrim($provider->base_url, '/').'/v1/embeddings', [
                'model' => $modelId,
                'input' => $texts,
            ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("{$provider->name} connection failed: {$e->getMessage()}", previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ProviderUnavailable("{$provider->name} returned HTTP {$status}.");
        }
        if ($response->failed()) {
            throw new ProviderRejected("{$provider->name} rejected the embedding request (HTTP {$status}).");
        }

        $rows = (array) $response->json('data', []);
        usort($rows, fn ($a, $b): int => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));

        return new EmbeddingResponse(
            array_map(fn ($row): array => array_map('floatval', array_values((array) ($row['embedding'] ?? []))), $rows),
            (int) ($response->json('usage.prompt_tokens') ?? $response->json('usage.total_tokens') ?? 0),
        );
    }
}
