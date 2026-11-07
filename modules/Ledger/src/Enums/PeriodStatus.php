<?php

declare(strict_types=1);

namespace Modules\Ledger\Enums;

enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
