<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Affiliates\Affiliates;

/** Daily (RF1): referral rewards past the 30-day hold become approved, or void if the brokerage left. */
final class SettleAffiliates extends Command
{
    protected $signature = 'billing:affiliates-settle';

    protected $description = 'Approve or void referral rewards after the 30-day hold';

    public function handle(Affiliates $affiliates): int
    {
        $r = $affiliates->settle();
        $this->info("{$r['approved']} approved, {$r['void']} void.");

        return self::SUCCESS;
    }
}
