<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Enums;

enum CellStatus: string
{
    case Active = 'active';
    case Draining = 'draining';
    case Full = 'full';
    case Maintenance = 'maintenance';
}
