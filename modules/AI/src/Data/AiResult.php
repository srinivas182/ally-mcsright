<?php

declare(strict_types=1);

namespace Modules\AI\Data;

final readonly class AiResult
{
    /**
     * @param  array<string, mixed>|null  $json  decoded output when an output schema was requested
     * @param  list<string>  $sourceRecordIds
     */
    public function __construct(
        public string $text,
        public ?array $json,
        public string $requestUlid,
        public string $providerKey,
        public string $modelId,
        public int $tokensIn,
        public int $tokensOut,
        public int $costMicrocents,
        public int $latencyMs,
        public array $sourceRecordIds,
    ) {}
}
