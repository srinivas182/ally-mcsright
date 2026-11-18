<?php

declare(strict_types=1);

namespace Modules\Network\Console;

use Illuminate\Console\Command;
use Modules\Network\NetworkReferrals;

/** Hourly (AR1): referrals not answered in 72 hours are withdrawn and the client's details removed. */
final class ExpireReferrals extends Command
{
    protected $signature = 'network:expire';

    protected $description = 'Withdraw referral-network referrals not answered in 72 hours';

    public function handle(NetworkReferrals $referrals): int
    {
        $this->info($referrals->expire().' referral(s) withdrawn.');

        return self::SUCCESS;
    }
}
