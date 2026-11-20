<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower\Console;

use Illuminate\Console\Command;
use Modules\Core\Watchtower\Alerts;

/** Daily at 8 am Central (OB1): the Watchtower report — new problems, what is open, what is failing. */
final class ReportCommand extends Command
{
    protected $signature = 'watchtower:report';

    protected $description = 'Email HQ the daily Watchtower report';

    public function handle(Alerts $alerts): int
    {
        $this->info($alerts->digest() ? 'Report sent.' : 'Not sent: the daily report is off, or no one is set to receive it.');

        return self::SUCCESS;
    }
}
