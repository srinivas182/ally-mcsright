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
 * Google Gemini batchEmbedContents. Gemini does not report token usage for embeddings, so
 * tokens are estimated (4 characters per token) for cost tracking.
 */
final class GeminiEmbeddings implements EmbeddingAdapter
{
    public function __construct(private readonly Http $http) {}

    public function embed(AiProvider $provider, string $modelId, array $texts, int $timeoutSeconds): EmbeddingResponse
    {
        $model = 'models/'.$modelId;

        try {
            $response = $this->http
                ->timeout($timeoutSeconds)
                ->withHeaders(['x-goog-api-key' => (string) $provider->apiKey()])
                ->acceptJson()
                ->post(rtrim($provider->base_url, '/').'/v1beta/'.$model.':batchEmbedContents', [
                    'requests' => array_map(fn (string $text): array => [
                        'model' => $model,
                        'content' => ['parts' => [['text' => $text]]],
                    ], $texts),
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("Gemini connection failed: {$e->getMessage()}", previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ProviderUnavailable("Gemini returned HTTP {$status}.");
        }
        if ($response->failed()) {
            throw new ProviderRejected("Gemini rejected the embedding request (HTTP {$status}).");
        }

        $vectors = array_map(
            fn ($row): array => array_map('floatval', array_values((array) ($row['values'] ?? []))),
            (array) $response->json('embeddings', []),
        );

        return new EmbeddingResponse(array_values($vectors), (int) ceil(array_sum(array_map('strlen', $texts)) / 4));
    }
}
