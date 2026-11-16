<?php

declare(strict_types=1);

namespace Modules\Core\Listings;

/**
 * The status the MLS shows for a listing (RESO StandardStatus), for the MLS status guard (specs/ai/02, §20).
 * The Listings module answers from the MLS feed; without it the guard cannot check and stays quiet.
 */
interface MlsStatus
{
    /** The listing's StandardStatus ("Active", "Pending", "Closed" …), or null when it is not in the brokerage's feed. */
    public function status(int $tenantId, string $mlsNumber): ?string;
}
