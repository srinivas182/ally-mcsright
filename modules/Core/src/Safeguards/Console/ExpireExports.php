<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards\Console;

use Illuminate\Console\Command;
use Modules\Core\Safeguards\BrokerageExport;

/** Deletes brokerage exports older than 7 days (DS1). */
final class ExpireExports extends Command
{
    protected $signature = 'safeguards:expire-exports';

    protected $description = 'Delete brokerage exports past their 7 days';

    public function handle(): int
    {
        $this->info(BrokerageExport::expire().' export(s) removed.');

        return self::SUCCESS;
    }
}
