<?php

declare(strict_types=1);

namespace Modules\Roster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Modules\Roster\Verification\ServiceVerifier;
use Throwable;

/**
 * LV1: downloads the licence lists states publish for free and loads them, every day, so licences given at sign-up
 * (and agents' licences) are checked automatically. Texas: TREC's broker and sales agent list on the Texas Open Data
 * Portal (updated daily). Florida: DBPR's real estate sales associates and brokers list (updated weekly). A state that
 * fails to download keeps its last list.
 */
final class SyncLicenceData extends Command
{
    /** state => [url, columns by position or null to find them by name] */
    public const SOURCES = [
        'TX' => ['https://data.texas.gov/api/views/s7ft-44qi/rows.csv?accessType=DOWNLOAD', null],
        // DBPR's layout (no header row): 1 licensee name, 11 licence number, 12 primary status, 16 expiration date.
        'FL' => ['https://www2.myfloridalicense.com/sto/file_download/extracts/REALESTATE2501LICENSE_1.csv', ['number' => 11, 'name' => 1, 'status' => 12, 'expires' => 16]],
    ];

    protected $signature = 'roster:licence-sync {--state= : Only this state}';

    protected $description = 'Download and load the public licence lists states publish (Texas, Florida)';

    public function handle(): int
    {
        $only = strtoupper((string) $this->option('state'));
        $failed = 0;
        foreach (self::SOURCES as $state => [$url, $columns]) {
            if ($only !== '' && $only !== $state) {
                continue;
            }
            $tmp = (string) tempnam(sys_get_temp_dir(), 'lic');
            try {
                Http::timeout(600)->withHeaders(['User-Agent' => 'RightAlly licence check'])->sink($tmp)->get($url)->throw();
                $n = ServiceVerifier::loadFile($state, $tmp, $columns);
                $n > 0 ? $this->info("{$state}: {$n} licences loaded.") : $this->warn("{$state}: the file had no licences; the last list is kept.");
                $failed += $n > 0 ? 0 : 1;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("{$state}: could not download the licence list ({$e->getMessage()}); the last list is kept.");
            } finally {
                @unlink($tmp);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
