<?php

declare(strict_types=1);

namespace Modules\Core\Deals;

final class NoBuyerAgreementGuard implements BuyerAgreementGuard
{
    public function missing(int $contactId): ?string
    {
        return null;
    }
}
