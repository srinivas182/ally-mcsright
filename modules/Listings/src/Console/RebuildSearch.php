<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use Illuminate\Console\Command;
use Modules\Listings\Models\Listing;
use Modules\Listings\Search\SearchIndex;
use Modules\Listings\Sync\Duplicates;

/** SR1: refreshes cross-MLS duplicates for every listing and rebuilds the search server's index. */
final class RebuildSearch extends Command
{
    protected $signature = 'listings:search-rebuild {--duplicates : Also recompute cross-MLS duplicates for every listing}';

    protected $description = 'Rebuild the fast listing search index (and optionally cross-MLS duplicates).';

    public function handle(SearchIndex $index, Duplicates $duplicates): int
    {
        if ($this->option('duplicates')) {
            Listing::query()->select('id')->chunkById(1000, function ($rows) use ($duplicates): void {
                $duplicates->refresh(array_values($rows->pluck('id')->map(fn ($v): int => (int) $v)->all()));
            });
            $this->info('Duplicates refreshed.');
        }
        if ($index->engine() === null) {
            $this->warn('No search server is connected (Outside services → Fast listing search).');

            return self::SUCCESS;
        }
        $this->info($index->rebuild().' listings sent to the search server.');

        return self::SUCCESS;
    }
}
