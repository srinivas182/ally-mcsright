<?php

declare(strict_types=1);

namespace Modules\Listings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;

/** One sync run for one MLS; never two at once for the same MLS. */
final class SyncMlsSource implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(ListingSync $sync): void
    {
        $source = MlsSource::query()->find($this->sourceId);
        if ($source !== null && $source->status !== 'paused') {
            $sync->run($source);
        }
    }
}
