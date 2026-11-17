<?php

declare(strict_types=1);

namespace Modules\Core\Deals;

/** Without List-IQ: nothing to show. */
final class NoListingActivity implements ListingActivity
{
    public function forSeller(int $dealId, string $locale): ?array
    {
        return null;
    }
}
