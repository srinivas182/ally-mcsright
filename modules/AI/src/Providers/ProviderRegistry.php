<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Modules\AI\Contracts\EmbeddingAdapter;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Embeddings\GeminiEmbeddings;
use Modules\AI\Embeddings\OpenAiCompatibleEmbeddings;
use Modules\AI\Models\AiProvider;

/**
 * Maps a provider's adapter name to its implementation. Tests can substitute an adapter for a
 * provider key with fake().
 */
final class ProviderRegistry
{
    private const ADAPTERS = [
        'anthropic' => AnthropicAdapter::class,
        'openai_compatible' => OpenAiCompatibleAdapter::class,
        'gemini' => GeminiAdapter::class,
    ];

    private const EMBEDDING_ADAPTERS = [
        'openai_compatible' => OpenAiCompatibleEmbeddings::class,
        'gemini' => GeminiEmbeddings::class,
    ];

    /** @var array<string, ProviderAdapter> */
    private array $fakes = [];

    /** @var array<string, EmbeddingAdapter> */
    private array $embeddingFakes = [];

    public function __construct(private readonly Container $container) {}

    public function adapterFor(AiProvider $provider): ProviderAdapter
    {
        if (isset($this->fakes[$provider->key])) {
            return $this->fakes[$provider->key];
        }

        $class = self::ADAPTERS[$provider->adapter] ?? throw new InvalidArgumentException("Unknown AI adapter [{$provider->adapter}].");

        /** @var ProviderAdapter */
        return $this->container->make($class);
    }

    public function embeddingAdapterFor(AiProvider $provider): EmbeddingAdapter
    {
        if (isset($this->embeddingFakes[$provider->key])) {
            return $this->embeddingFakes[$provider->key];
        }

        $class = self::EMBEDDING_ADAPTERS[$provider->adapter]
            ?? throw new InvalidArgumentException("Provider adapter [{$provider->adapter}] does not support embeddings.");

        /** @var EmbeddingAdapter */
        return $this->container->make($class);
    }

    public function fakeEmbeddings(string $providerKey, EmbeddingAdapter $adapter): void
    {
        $this->embeddingFakes[$providerKey] = $adapter;
    }

    public function fake(string $providerKey, ProviderAdapter $adapter): void
    {
        $this->fakes[$providerKey] = $adapter;
    }
}
