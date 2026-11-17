<?php

declare(strict_types=1);

namespace Modules\Core\Deals;

/**
 * How a listing is doing, for the seller in the deal portal (SH1, specs/listings/04): showings, buyers' feedback,
 * website activity and the agent's note. Implemented by List-IQ so Transactions never reads its tables; without it,
 * the portal shows nothing about the listing.
 */
interface ListingActivity
{
    /**
     * @return array<string, mixed>|null in the seller's language; null when the deal has no listing in List-IQ
     */
    public function forSeller(int $dealId, string $locale): ?array;
}
