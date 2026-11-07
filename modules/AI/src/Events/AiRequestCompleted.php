<?php

declare(strict_types=1);

namespace Modules\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Listened to by the audit log (specs/core/04) to record the source records behind every answer.
 */
final class AiRequestCompleted
{
    use Dispatchable;

    /**
     * @param  list<string>  $sourceRecordIds
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $requestUlid,
        public readonly string $featureKey,
        public readonly array $sourceRecordIds,
    ) {}
}
