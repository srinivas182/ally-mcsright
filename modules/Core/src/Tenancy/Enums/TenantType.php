<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Enums;

enum TenantType: string
{
    case Hq = 'hq';
    case Brokerage = 'brokerage';
    case Solo = 'solo';
}
