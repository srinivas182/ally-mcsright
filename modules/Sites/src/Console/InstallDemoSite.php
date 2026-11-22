<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use Illuminate\Console\Command;
use Modules\Sites\Content\DemoSite;

/** TP4: creates the demo website shown while choosing a design at sign-up (once; safe on every deploy). */
final class InstallDemoSite extends Command
{
    protected $signature = 'sites:demo-site';

    protected $description = 'Create the demo website used to preview designs at sign-up';

    public function handle(DemoSite $demo): int
    {
        $demo->install();
        $this->info('Demo website ready at https://'.DemoSite::host().'.');

        return self::SUCCESS;
    }
}
