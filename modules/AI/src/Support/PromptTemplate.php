<?php

declare(strict_types=1);

namespace Modules\AI\Support;

final readonly class PromptTemplate
{
    public function __construct(
        public string $key,
        public string $version,
        public string $body,
        /** BV1: the brokerage's Brand Voice applies (people-facing writing). Set with "voice: true" in the front matter. */
        public bool $voice = false,
    ) {}
}
