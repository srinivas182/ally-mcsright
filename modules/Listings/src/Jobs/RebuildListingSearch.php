<?php

declare(strict_types=1);

namespace Modules\Listings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Listings\Search\SearchIndex;

/** SR1: rebuilds the whole search index (HQ's button, or after switching provider). */
final class RebuildListingSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public function handle(SearchIndex $index): void
    {
        $index->rebuild();
    }
}
