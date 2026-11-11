<?php

declare(strict_types=1);

namespace Modules\Compensation\Enums;

enum PlanStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
