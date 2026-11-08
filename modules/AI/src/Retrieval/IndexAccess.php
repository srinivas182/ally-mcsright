<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

/**
 * Access metadata stored with every indexed chunk; the retriever filters on it.
 */
final readonly class IndexAccess
{
    /**
     * @param  list<int>  $teamIds
     */
    public function __construct(
        public ?int $ownerUserId,
        public array $teamIds = [],
        public ?int $officeId = null,
        public bool $sensitive = false,
    ) {}
}
