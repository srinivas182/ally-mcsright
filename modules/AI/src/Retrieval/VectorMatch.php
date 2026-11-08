<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

use Modules\AI\Models\VectorDocument;

final readonly class VectorMatch
{
    public function __construct(
        public VectorDocument $document,
        public float $score,
    ) {}
}
