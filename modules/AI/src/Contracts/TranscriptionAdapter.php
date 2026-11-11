<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiProvider;

/**
 * Speech to text. The audio is sent once and never stored.
 */
interface TranscriptionAdapter
{
    /**
     * @throws ProviderUnavailable
     * @throws ProviderRejected
     */
    public function transcribe(AiProvider $provider, string $modelId, string $audio, string $mimeType, ?string $language, int $timeoutSeconds): ProviderResponse;
}
