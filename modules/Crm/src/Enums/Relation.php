<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

enum Relation: string
{
    case Spouse = 'spouse';
    case Partner = 'partner';
    case Referrer = 'referrer';
    case Attorney = 'attorney';
    case Lender = 'lender';
    case Other = 'other';
}
