<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
