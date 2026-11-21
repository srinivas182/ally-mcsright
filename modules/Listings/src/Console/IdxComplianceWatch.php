<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use Illuminate\Console\Command;
use Modules\Listings\Idx\Agreements;

/** IDX6: nightly, each live MLS board's website opens and shows the MLS notice; problems go to HQ. */
final class IdxComplianceWatch extends Command
{
    protected $signature = 'idx:compliance';

    protected $description = 'Check every live MLS board\'s website still opens and shows its MLS notice';

    public function handle(Agreements $agreements): int
    {
        $r = $agreements->complianceWatch();
        $this->info("{$r['checked']} checked, {$r['problems']} need a look.");

        return self::SUCCESS;
    }
}
