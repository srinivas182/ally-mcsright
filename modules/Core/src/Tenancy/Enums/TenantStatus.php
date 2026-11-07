<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Enums;

enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closing = 'closing';
    case Closed = 'closed';

    /** Whether users may use the tenant normally. */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
