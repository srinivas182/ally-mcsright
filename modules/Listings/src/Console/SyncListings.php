<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Listings\Jobs\SyncMlsSource;
use Modules\Listings\Models\MlsSource;

/** Every minute: queue a sync for each MLS that is due (its interval passed, or more pages are waiting). */
final class SyncListings extends Command
{
    protected $signature = 'listings:sync';

    protected $description = 'Queue MLS syncs that are due';

    public function handle(): int
    {
        $n = 0;
        foreach (MlsSource::query()->whereIn('status', ['setup', 'active', 'error'])->whereNotNull('credentials')->get() as $source) {
            $due = $source->next_link !== null || $source->last_success_at === null || $source->last_success_at->lte(CarbonImmutable::now()->subMinutes($source->sync_interval_minutes));
            if ($due && ($source->backoff_until === null || $source->backoff_until->isPast())) {
                SyncMlsSource::dispatch($source->id);
                $n++;
            }
        }
        $this->info("{$n} MLS sync(s) queued.");

        return self::SUCCESS;
    }
}
