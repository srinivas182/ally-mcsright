<?php

declare(strict_types=1);

namespace Modules\Listings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Listings\Search\SearchIndex;
use Modules\Listings\Sync\Duplicates;

/** SR1: after a sync, refreshes cross-MLS duplicates and the search server for the changed listings. */
final class RefreshListingSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @param  list<int>  $ids */
    public function __construct(public readonly array $ids) {}

    public function handle(Duplicates $duplicates, SearchIndex $index): void
    {
        $index->sync($duplicates->refresh($this->ids));
    }
}
