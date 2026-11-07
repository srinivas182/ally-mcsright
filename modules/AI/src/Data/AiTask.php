<?php

declare(strict_types=1);

namespace Modules\AI\Data;

/**
 * A request for AI work. Context is a list of RetrievedRecord objects only; documents, emails
 * and form text written by other people go in $untrustedContent and are treated as data,
 * never as instructions.
 */
final readonly class AiTask
{
    /**
     * @param  array<string, string>  $inputs  values for the prompt template
     * @param  list<RetrievedRecord>  $context
     * @param  list<string>  $untrustedContent
     * @param  array<string, mixed>|null  $outputSchema  JSON schema subset (see JsonShape)
     */
    public function __construct(
        public string $featureKey,
        public string $promptKey,
        public string $idempotencyKey,
        public array $inputs = [],
        public array $context = [],
        public array $untrustedContent = [],
        public ?array $outputSchema = null,
        public ?int $userId = null,
    ) {}
}
