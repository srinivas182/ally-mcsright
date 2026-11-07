<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Enums;

enum SslStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
}
