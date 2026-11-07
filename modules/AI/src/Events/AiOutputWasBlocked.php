<?php

declare(strict_types=1);

namespace Modules\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Security alert: an answer referenced identifiers outside the permitted context. Carries only
 * the kinds of identifiers, never the values.
 */
final class AiOutputWasBlocked
{
    use Dispatchable;

    /**
     * @param  list<string>  $violationKinds
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $requestUlid,
        public readonly string $featureKey,
        public readonly array $violationKinds,
    ) {}
}
