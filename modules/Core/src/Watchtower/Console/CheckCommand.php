<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower\Console;

use Illuminate\Console\Command;
use Modules\Core\Watchtower\Alerts;
use Modules\Core\Watchtower\Recorder;
use Modules\Core\Watchtower\SlowPages;
use Modules\Core\Watchtower\SystemChecks;

/** Every minute (OB1): check every part of RightAlly, ask the AI about new problems, then email whatever is due. */
final class CheckCommand extends Command
{
    protected $signature = 'watchtower:check';

    protected $description = 'Check databases, cache, storage, the queue, scheduled tasks and slow pages; email HQ about problems';

    public function handle(SystemChecks $checks, Alerts $alerts, SlowPages $slow, Recorder $recorder): int
    {
        $results = $checks->run();
        $failing = array_keys(array_filter($results, fn (array $r): bool => ! $r['ok']));
        $slow->evaluate($recorder);
        $alerts->requestAnalyses();
        $sent = $alerts->sendDue();
        $this->info(($failing === [] ? 'All '.count($results).' checks passing.' : 'Failing: '.implode(', ', $failing).'.')." {$sent} email(s) sent.");

        return self::SUCCESS;
    }
}
