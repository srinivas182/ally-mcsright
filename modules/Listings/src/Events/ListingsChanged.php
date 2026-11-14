<?php

declare(strict_types=1);

namespace Modules\Listings\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Listings were added or changed by a sync (search indexing, alerts, page caches listen). */
final class ListingsChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $listingIds
     */
    public function __construct(public readonly int $sourceId, public readonly array $listingIds, public readonly string $kind) {}
}
