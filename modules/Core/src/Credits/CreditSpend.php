<?php

declare(strict_types=1);

namespace Modules\Core\Credits;

use Carbon\CarbonImmutable;

/**
 * What the current brokerage spent on an action in a period, in credits and at the credit's price (specs/billing/01).
 * Billing implements it; without Billing nothing is spent.
 */
interface CreditSpend
{
    /**
     * @return array{credits: int, microcents: int}
     */
    public function spent(string $actionKey, CarbonImmutable $from, CarbonImmutable $to): array;
}
