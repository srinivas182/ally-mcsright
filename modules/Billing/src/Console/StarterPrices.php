<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Billing\Builder\StarterPrices as Prices;

/** PB3: adds RightAlly's starter packages, agent seats and brokerage share where missing. Safe to run again. */
final class StarterPrices extends Command
{
    protected $signature = 'billing:starter-prices';

    protected $description = 'Add the starter brokerage package, agent seats and brokerage share (only what is missing)';

    public function handle(Prices $prices): int
    {
        $added = $prices->install(new User);
        foreach ($added as $line) {
            $this->line('Added: '.$line);
        }
        $this->info($added === [] ? 'Nothing to add: HQ already has these.' : count($added).' added. Edit them any time in HQ › Packages.');

        return self::SUCCESS;
    }
}
