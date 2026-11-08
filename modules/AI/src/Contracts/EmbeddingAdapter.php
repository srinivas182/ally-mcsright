<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Data\EmbeddingResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;

interface EmbeddingAdapter
{
    /**
     * @param  list<string>  $texts  already redacted by the gateway
     *
     * @throws ProviderUnavailable
     * @throws ProviderRejected
     */
    public function embed(AiProvider $provider, string $modelId, array $texts, int $timeoutSeconds): EmbeddingResponse;
}
