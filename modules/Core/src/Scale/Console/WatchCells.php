<?php

declare(strict_types=1);

namespace Modules\Core\Scale\Console;

use Illuminate\Console\Command;
use Modules\Core\Scale\Cells;

/** Every 10 minutes (PS1): close full cells to new brokerages, reopen ones with room, warn HQ early. */
final class WatchCells extends Command
{
    protected $signature = 'cells:watch';

    protected $description = 'Close full cells, reopen cells with room, and warn HQ before cells fill up';

    public function handle(Cells $cells): int
    {
        $r = $cells->watch();
        $this->info(count($r['closed']).' closed, '.count($r['reopened']).' reopened, '.count($r['warned']).' warning(s)'.($r['no_room'] ? '; NO CELL HAS ROOM' : '').'.');

        return self::SUCCESS;
    }
}
