<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use Illuminate\Console\Command;
use Modules\Sites\Content\DemoPhotos;

/** TP2: downloads the website designs' demo photos (from Pexels) once, in every size. Runs on deploy; safe to re-run. */
final class InstallDemoPhotos extends Command
{
    protected $signature = 'sites:demo-photos';

    protected $description = 'Download the demo photos used by website designs (only those not yet stored)';

    public function handle(DemoPhotos $photos): int
    {
        $r = $photos->install();
        $this->info("Demo photos: {$r['stored']} downloaded".($r['failed'] !== [] ? '; could not fetch '.count($r['failed']).' (they show from Pexels until the next deploy)' : '').'.');

        return self::SUCCESS;
    }
}
