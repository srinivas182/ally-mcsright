<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;

/**
 * Providers with a batch API (lower price, results within 24 hours).
 */
interface BatchProviderAdapter
{
    /**
     * @param  array<string, ProviderRequest>  $requests  keyed by custom id
     * @return string the provider's batch id
     *
     * @throws ProviderUnavailable
     * @throws ProviderRejected
     */
    public function submitBatch(AiProvider $provider, array $requests): string;

    /**
     * Null while still processing; otherwise each custom id's response, or an error string.
     *
     * @return array<string, ProviderResponse|string>|null
     */
    public function fetchBatch(AiProvider $provider, string $batchId): ?array;
}
