<?php

declare(strict_types=1);

namespace Modules\Core\Deals;

/**
 * "Agreement needed" (AP1, specs/listings/05): since the 2024 NAR settlement, an agent must have a written agreement
 * with a buyer before touring a home with them. The Appointments module answers for a contact; without it, nothing
 * is checked.
 */
interface BuyerAgreementGuard
{
    /** @return string|null a plain-words reason a tour needs an agreement first, or null when it may go ahead */
    public function missing(int $contactId): ?string;
}
