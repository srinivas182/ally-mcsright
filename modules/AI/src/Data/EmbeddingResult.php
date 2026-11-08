<?php

declare(strict_types=1);

namespace Modules\AI\Data;

final readonly class EmbeddingResult
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public array $vectors,
        public string $modelId,
        public int $dimensions,
        public string $requestUlid,
        public int $costMicrocents,
    ) {}
}
