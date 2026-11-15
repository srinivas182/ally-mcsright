<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use Carbon\CarbonImmutable;
use Modules\Billing\Credits\Models\CreditLedgerEntry;
use Modules\Core\Credits\CreditSpend;

/** Credits the brokerage spent on an action (charges less refunds), priced at today's credit value. */
final class LedgerCreditSpend implements CreditSpend
{
    public function __construct(private readonly RateCard $rates) {}

    public function spent(string $actionKey, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $credits = -(int) CreditLedgerEntry::query()->where('action_key', $actionKey)->whereIn('entry_type', ['consume', 'refund'])
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->sum('amount');

        return ['credits' => $credits, 'microcents' => $credits * $this->rates->creditValueMicrocents()];
    }
}
