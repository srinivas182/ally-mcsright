<?php

declare(strict_types=1);

namespace Modules\Compensation\Enums;

enum Side: string
{
    case Listing = 'listing';
    case Buying = 'buying';
}
