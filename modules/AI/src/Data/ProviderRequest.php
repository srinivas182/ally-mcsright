<?php

declare(strict_types=1);

namespace Modules\AI\Data;

/**
 * What an adapter sends to a provider: already built, redacted and wrapped by the gateway.
 */
final readonly class ProviderRequest
{
    public function __construct(
        public string $modelId,
        public string $system,
        public string $userMessage,
        public int $maxOutputTokens,
        public string $temperature,
        public int $timeoutSeconds,
    ) {}
}
