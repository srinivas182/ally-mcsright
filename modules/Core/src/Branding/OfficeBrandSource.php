<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/** BR2: the current brokerage's back-office brand (from its website settings), or null for RightAlly's own look. */
interface OfficeBrandSource
{
    public function forCurrentTenant(): ?OfficeBrand;
}
