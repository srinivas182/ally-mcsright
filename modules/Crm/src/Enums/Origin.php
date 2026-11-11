<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

/**
 * Personal sphere (the agent's own) or company (provided by the brokerage).
 */
enum Origin: string
{
    case Personal = 'personal';
    case Company = 'company';
}
