<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;

interface ProviderAdapter
{
    /**
     * @throws ProviderUnavailable timeouts, rate limits, 5xx (try the next model)
     * @throws ProviderRejected other 4xx (do not retry)
     */
    public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse;
}
