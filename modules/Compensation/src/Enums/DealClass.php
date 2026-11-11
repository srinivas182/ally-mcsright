<?php

declare(strict_types=1);

namespace Modules\Compensation\Enums;

/**
 * Deal classes can carry their own split (best-in-class review, addition 5).
 */
enum DealClass: string
{
    case Sale = 'sale';
    case Lease = 'lease';
    case Commercial = 'commercial';
    case ReferralOnly = 'referral_only';
    case NewConstruction = 'new_construction';
    case Bpo = 'bpo';
}
