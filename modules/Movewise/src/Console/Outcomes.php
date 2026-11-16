<?php

declare(strict_types=1);

namespace Modules\Movewise\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Movewise\Growth\AdBudget;
use Modules\Movewise\Outcomes\Invoices;
use Modules\Movewise\Outcomes\Outcomes as Service;
use Modules\Movewise\Outcomes\Scorecards;

/** Movewise outcomes (specs/movewise/06): daily status reminders and fallbacks; weekly scorecards; monthly settlements. */
final class Outcomes extends Command
{
    protected $signature = 'movewise:outcomes {--scorecards : Recalculate every scorecard and tier} {--settle= : Build partner settlements for a month (YYYY-MM)}';

    protected $description = 'Movewise status reminders and share-at-close fallbacks, scorecards, and partner settlements';

    public function handle(Service $outcomes, Scorecards $scorecards, Invoices $invoices): int
    {
        if ($this->option('scorecards')) {
            $this->info($scorecards->recalculate().' scorecards.');

            return self::SUCCESS;
        }
        if ($this->option('settle') !== null) {
            $period = (string) $this->option('settle') ?: CarbonImmutable::now()->subMonth()->format('Y-m');
            $this->info($invoices->settle($period).' settlements for '.$period.'.');

            return self::SUCCESS;
        }
        try {
            app(AdBudget::class)->sync(); // spend from RightAlly's own Meta and Google ad accounts first
        } catch (\Throwable $e) {
            report($e);
        }
        $ads = app(AdBudget::class)->check();
        $r = $outcomes->sweep();
        $this->info($r['reminded'].' reminded, '.$r['paused'].' paused, '.$r['fallback'].' moved to the per-connection price; ads '.$ads.'.');

        return self::SUCCESS;
    }
}
