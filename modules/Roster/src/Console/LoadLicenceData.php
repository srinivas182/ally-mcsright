<?php

declare(strict_types=1);

namespace Modules\Roster\Console;

use Illuminate\Console\Command;
use Modules\Roster\Verification\ServiceVerifier;

/** LN4: loads a state's published licence list (CSV) for free automatic licence checks. */
final class LoadLicenceData extends Command
{
    protected $signature = 'roster:licence-data {state : Two-letter state code} {file : The state\'s licence list (CSV)}';

    protected $description = 'Load a state\'s public licence list for automatic licence checks';

    public function handle(): int
    {
        $state = strtoupper((string) $this->argument('state'));
        $file = (string) $this->argument('file');
        if (preg_match('/^[A-Z]{2}$/', $state) !== 1 || ! is_readable($file)) {
            $this->error('Give a two-letter state and a readable CSV file.');

            return self::FAILURE;
        }
        $n = ServiceVerifier::load($state, (string) file_get_contents($file));
        $n > 0 ? $this->info("{$n} {$state} licences loaded.") : $this->error('No licences found. The file needs a licence number column and a status column.');

        return $n > 0 ? self::SUCCESS : self::FAILURE;
    }
}
