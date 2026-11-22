<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use Illuminate\Console\Command;
use Modules\Listings\Samples\SampleHomes;

/**
 * TP1: installs (or refreshes) the sample homes every website shows before its MLS board is live. Runs on every
 * deploy; safe to run again (photos already stored are not downloaded again).
 */
final class InstallSampleHomes extends Command
{
    protected $signature = 'listings:sample-homes {--force : Install even when LISTINGS_SAMPLE_HOMES is off}';

    protected $description = 'Install or refresh the sample homes (with photos) shown on websites before an MLS board is live';

    public function handle(SampleHomes $samples): int
    {
        if (! SampleHomes::enabled() && ! (bool) $this->option('force')) {
            $this->info('Sample homes are switched off (LISTINGS_SAMPLE_HOMES=false).');

            return self::SUCCESS;
        }
        $result = $samples->install();
        $this->info("Sample homes ready: {$result['homes']} homes in {$result['sources']} states; {$result['photos']} homes have photos to download (queued).");

        return self::SUCCESS;
    }
}
