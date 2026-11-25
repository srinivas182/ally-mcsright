<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Modules\Transactions\Models\Deal;

/** DA1: a dual deal goes under contract only with the signed dual agency (or intermediary) consent uploaded. */
final class DualConsentGuard implements DealGuard
{
    public function check(Deal $deal): ?string
    {
        if ($deal->type !== 'dual' || DualAgency::consentIn($deal)) {
            return null;
        }

        return 'Upload the signed '.mb_strtolower(DualAgency::CONSENT_ITEM).' (both the buyer and the seller sign it) before this deal goes under contract.';
    }
}
