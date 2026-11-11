<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

enum Lifecycle: string
{
    case Lead = 'lead';
    case Active = 'active';
    case UnderContract = 'under_contract';
    case PastClient = 'past_client';
    case Sphere = 'sphere';
    case Archived = 'archived';
}
