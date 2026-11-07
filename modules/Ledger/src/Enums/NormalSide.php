<?php

declare(strict_types=1);

namespace Modules\Ledger\Enums;

enum NormalSide: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
