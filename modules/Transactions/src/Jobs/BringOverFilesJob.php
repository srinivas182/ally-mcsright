<?php

declare(strict_types=1);

namespace Modules\Transactions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Support\Connections;
use Modules\Transactions\Connect\BringOverFiles;
use RuntimeException;
use Throwable;

/** Files an uploaded SkySlope or Lone Wolf export into the imported deals, in the background (specs/connect/12). */
final class BringOverFilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $importId) {}

    public function handle(BringOverFiles $files): void
    {
        try {
            $files->run($this->importId);
        } catch (Throwable $e) {
            $db = DB::connection(Connections::cell());
            $path = $db->table('tx_file_imports')->where('id', $this->importId)->value('path');
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }
            $db->table('tx_file_imports')->where('id', $this->importId)->where('status', 'queued')->update(['status' => 'failed', 'path' => null,
                'error' => $e instanceof RuntimeException ? mb_substr($e->getMessage(), 0, 300) : 'Something went wrong reading that ZIP. Please try again.', 'updated_at' => now()]);
            if (! $e instanceof RuntimeException) {
                report($e);
            }
        }
    }
}
