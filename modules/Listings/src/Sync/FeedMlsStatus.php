<?php

declare(strict_types=1);

namespace Modules\Listings\Sync;

use Modules\Core\Listings\MlsStatus;
use Modules\Listings\Models\Listing;

/** The MLS status of a listing from the brokerage's own licensed feeds (specs/ai/02, §20). */
final class FeedMlsStatus implements MlsStatus
{
    public function status(int $tenantId, string $mlsNumber): ?string
    {
        $mlsNumber = trim($mlsNumber);
        if ($mlsNumber === '') {
            return null;
        }
        $status = Listing::query()->visibleTo($tenantId, 'idx')->where('listing_id', $mlsNumber)->orderByDesc('id')->value('standard_status');

        return is_string($status) && $status !== '' ? $status : null;
    }
}
