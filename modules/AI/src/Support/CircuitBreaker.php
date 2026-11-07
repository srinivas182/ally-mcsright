<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Per-provider circuit breaker: opens after 5 failures within 60 seconds and stays open for
 * 30 seconds, after which one trial request is allowed (half-open).
 */
final class CircuitBreaker
{
    public const THRESHOLD = 5;

    public const WINDOW_SECONDS = 60;

    public const OPEN_SECONDS = 30;

    public function __construct(private readonly Cache $cache) {}

    public function isOpen(string $providerKey): bool
    {
        $until = $this->cache->get($this->openKey($providerKey));

        return is_int($until) && $until > time();
    }

    public function recordFailure(string $providerKey): void
    {
        $key = $this->failuresKey($providerKey);
        $this->cache->add($key, 0, self::WINDOW_SECONDS);
        $failures = (int) $this->cache->increment($key);

        if ($failures >= self::THRESHOLD) {
            $this->cache->put($this->openKey($providerKey), time() + self::OPEN_SECONDS, self::OPEN_SECONDS + 5);
            $this->cache->forget($key);
        }
    }

    public function recordSuccess(string $providerKey): void
    {
        $this->cache->forget($this->failuresKey($providerKey));
        $this->cache->forget($this->openKey($providerKey));
    }

    private function failuresKey(string $providerKey): string
    {
        return "ai:circuit:{$providerKey}:failures";
    }

    private function openKey(string $providerKey): string
    {
        return "ai:circuit:{$providerKey}:open_until";
    }
}
