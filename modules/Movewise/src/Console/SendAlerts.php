<?php

declare(strict_types=1);

namespace Modules\Movewise\Console;

use Illuminate\Console\Command;
use Modules\Movewise\Alerts;

/** Daily: Movewise saved-search alerts (specs/movewise/02). */
final class SendAlerts extends Command
{
    protected $signature = 'movewise:alerts';

    protected $description = 'Email Movewise saved-search alerts that are due';

    public function handle(Alerts $alerts): int
    {
        $this->info($alerts->sendDue().' alert email(s) sent.');

        return self::SUCCESS;
    }
}
