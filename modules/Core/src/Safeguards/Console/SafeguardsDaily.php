<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Safeguards\BrokerageExport;
use Modules\Core\Safeguards\DatabaseMoves;
use Modules\Core\Safeguards\Structure;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\CellRouting;
use Modules\Core\Tenancy\Models\Cell;

/**
 * Daily data safeguards (DS2): removes old copies 14 days after a move, and brings each dedicated database's
 * structure in step with the main cell after a release (new tables and columns).
 */
final class SafeguardsDaily extends Command
{
    protected $signature = 'safeguards:daily';

    protected $description = 'Clean up old copies after moves and sync dedicated database structure';

    public function handle(DatabaseMoves $moves, CellRouting $routing): int
    {
        $this->info($moves->cleanUp().' old cop(ies) removed.');
        $main = DB::connection(Connections::cell());
        foreach (Cell::query()->where(fn ($q) => $q->where('dedicated', true)->orWhereNotNull('credentials'))->get() as $cell) { // PS1: shared cells added from HQ too
            try {
                $r = Structure::sync($main, DB::connection(DatabaseMoves::name($cell)), BrokerageExport::tables());
                $this->info("{$cell->name}: {$r['tables']} table(s), {$r['columns']} column(s) added.");
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$cell->name}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
