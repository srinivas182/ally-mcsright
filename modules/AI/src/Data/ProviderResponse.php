<?php

declare(strict_types=1);

namespace Modules\AI\Data;

final readonly class ProviderResponse
{
    public function __construct(
        public string $text,
        public int $tokensIn,
        public int $tokensOut,
        public int $cacheReadTokens = 0,
        // Seconds of audio, for speech-to-text billed per minute (0 when the provider bills by tokens).
        public float $audioSeconds = 0.0,
    ) {}
}
