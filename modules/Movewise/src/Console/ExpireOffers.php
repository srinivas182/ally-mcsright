<?php

declare(strict_types=1);

namespace Modules\Movewise\Console;

use Illuminate\Console\Command;
use Modules\Movewise\Matching\Connections;

/** Ends introductions the chosen agent didn't accept in time (specs/movewise/04): nobody is charged, and the person chooses again. */
final class ExpireOffers extends Command
{
    protected $signature = 'movewise:expire-offers';

    protected $description = 'Expire Movewise introductions not accepted in time and ask the person to choose again';

    public function handle(Connections $connections): int
    {
        $this->info($connections->expireDue().' expired.');

        return self::SUCCESS;
    }
}
