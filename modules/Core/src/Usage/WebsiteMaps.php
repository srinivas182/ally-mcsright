<?php

declare(strict_types=1);

namespace Modules\Core\Usage;

/**
 * Whether a website visitor may open the interactive map now, counting the view if so (specs/billing/03).
 * Billing implements the brokerage's monthly map allowance; without it every view is allowed.
 */
interface WebsiteMaps
{
    public function open(): bool;
}
