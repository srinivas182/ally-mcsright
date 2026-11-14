<?php

declare(strict_types=1);

namespace Modules\Listings\Feed;

use RuntimeException;

/** The MLS said "slow down" (HTTP 429). */
final class FeedThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds = 60)
    {
        parent::__construct('The MLS asked us to slow down.');
    }
}
