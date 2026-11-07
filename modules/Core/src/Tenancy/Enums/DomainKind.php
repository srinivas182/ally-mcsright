<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Enums;

enum DomainKind: string
{
    case Platform = 'platform';
    case Custom = 'custom';
}
