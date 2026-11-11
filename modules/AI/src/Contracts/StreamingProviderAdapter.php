<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Closure;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Exceptions\StreamWithheld;
use Modules\AI\Models\AiProvider;

interface StreamingProviderAdapter
{
    /**
     * Streams the answer, calling $onDelta with each piece of text as it arrives, and returns the
     * complete response (full text and token usage) at the end.
     *
     * $onDelta may throw StreamWithheld to stop the stream (output policy violated); adapters
     * must let that exception propagate and stop reading.
     *
     * @param  Closure(string): void  $onDelta
     *
     * @throws ProviderUnavailable
     * @throws ProviderRejected
     * @throws StreamWithheld
     */
    public function stream(AiProvider $provider, ProviderRequest $request, Closure $onDelta): ProviderResponse;
}
