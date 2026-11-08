<?php

declare(strict_types=1);

namespace Modules\AI\Data;

final readonly class EmbeddingResponse
{
    /**
     * @param  list<list<float>>  $vectors  one vector per input text, same order
     */
    public function __construct(
        public array $vectors,
        public int $tokens,
    ) {}
}
