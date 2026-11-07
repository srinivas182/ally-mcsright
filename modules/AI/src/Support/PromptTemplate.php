<?php

declare(strict_types=1);

namespace Modules\AI\Support;

final readonly class PromptTemplate
{
    public function __construct(
        public string $key,
        public string $version,
        public string $body,
    ) {}
}
