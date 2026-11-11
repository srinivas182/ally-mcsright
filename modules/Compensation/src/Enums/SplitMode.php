<?php

declare(strict_types=1);

namespace Modules\Compensation\Enums;

/**
 * Legacy rule 3 (percentage, flat fee) plus tiered splits.
 */
enum SplitMode: string
{
    case Percentage = 'percentage';
    case FlatFee = 'flat_fee';
    case Tiered = 'tiered';
}
