<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use Illuminate\Console\Command;
use Modules\Listings\Idx\AgreementWatch;

/** IDX11: weekly, each board's own agreement form is downloaded and compared with the version in use. */
final class IdxAgreementWatch extends Command
{
    protected $signature = 'idx:agreement-watch';

    protected $description = "Check every MLS board's agreement form for a new version";

    public function handle(AgreementWatch $watch): int
    {
        $r = $watch->run();
        $this->info("{$r['checked']} form(s) checked, {$r['changed']} new version(s), {$r['due']} to confirm.");

        return self::SUCCESS;
    }
}
