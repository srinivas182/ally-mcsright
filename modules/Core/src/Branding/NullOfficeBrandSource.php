<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/** No brokerage brand: the back office keeps RightAlly's look. */
final class NullOfficeBrandSource implements OfficeBrandSource
{
    public function forCurrentTenant(): ?OfficeBrand
    {
        return null;
    }
}
