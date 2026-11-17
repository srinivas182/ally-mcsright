<?php

declare(strict_types=1);

namespace Modules\Network\Console;

use Illuminate\Console\Command;
use Modules\Network\TrustScore;

/** Daily (AR2): every network profile's Referral Trust Score, so time-based parts (late fees, quiet referrals) count. */
final class RefreshTrust extends Command
{
    protected $signature = 'network:trust';

    protected $description = 'Recalculate every Referral Trust Score';

    public function handle(TrustScore $trust): int
    {
        $this->info($trust->refreshAll().' profile(s) scored.');

        return self::SUCCESS;
    }
}
