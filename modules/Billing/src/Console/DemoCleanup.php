<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Onboarding\TryDemo;

/** MK2, daily: delete "Try it now" guests and their made-up clients after a day. */
final class DemoCleanup extends Command
{
    protected $signature = 'billing:demo-cleanup';

    protected $description = 'Delete demo guests older than a day';

    public function handle(TryDemo $demo): int
    {
        $this->info($demo->cleanup().' demo guest(s) removed.');

        return self::SUCCESS;
    }
}
