<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use Illuminate\Console\Command;
use Modules\Listings\Idx\Subscriptions;

/** IDX4: reminds brokerages (and their managing broker) to sign an MLS agreement, 1 and 3 days after it is ready. */
final class RemindIdxSignatures extends Command
{
    protected $signature = 'idx:remind';

    protected $description = 'Remind brokerages to sign their MLS agreements (1 and 3 days on)';

    public function handle(Subscriptions $subscriptions): int
    {
        $this->info($subscriptions->remind().' reminder(s) sent.');

        return self::SUCCESS;
    }
}
