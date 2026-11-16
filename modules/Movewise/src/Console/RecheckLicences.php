<?php

declare(strict_types=1);

namespace Modules\Movewise\Console;

use Illuminate\Console\Command;
use Modules\Movewise\Program\Agents;

/** Re-checks Movewise agents' licences against state records every 30 days (specs/movewise/03); a lapse pauses them. */
final class RecheckLicences extends Command
{
    protected $signature = 'movewise:recheck-licences';

    protected $description = 'Re-check Movewise agents\' licences with state records and pause any that aren\'t active';

    public function handle(Agents $agents): int
    {
        $this->info($agents->recheckDue().' licences checked.');

        return self::SUCCESS;
    }
}
