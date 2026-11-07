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
    ) {}
}
