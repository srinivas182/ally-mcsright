<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Modules\AI\Contracts\ProviderAdapter;
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
    ];

    /** @var array<string, ProviderAdapter> */
    private array $fakes = [];

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

    public function fake(string $providerKey, ProviderAdapter $adapter): void
    {
        $this->fakes[$providerKey] = $adapter;
    }
}
