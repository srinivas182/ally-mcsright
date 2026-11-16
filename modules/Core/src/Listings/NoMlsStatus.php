<?php

declare(strict_types=1);

namespace Modules\Core\Listings;

/** No MLS feed: nothing to compare against. */
final class NoMlsStatus implements MlsStatus
{
    public function status(int $tenantId, string $mlsNumber): ?string
    {
        return null;
    }
}
