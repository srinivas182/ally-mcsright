<?php

declare(strict_types=1);

namespace Modules\Team\Console;

use Illuminate\Console\Command;
use Modules\Team\Help\Concierge;

/** SX6, daily: delete concierge spreadsheets 30 days after they were sent, or once the request is closed. */
final class CleanupConcierge extends Command
{
    protected $signature = 'team:concierge-cleanup';

    protected $description = 'Delete old concierge setup files';

    public function handle(): int
    {
        $this->info(Concierge::cleanup().' file(s) deleted.');

        return self::SUCCESS;
    }
}
