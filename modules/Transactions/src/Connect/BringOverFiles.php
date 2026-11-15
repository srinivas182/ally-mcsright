<?php

declare(strict_types=1);

namespace Modules\Transactions\Connect;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use RuntimeException;
use ZipArchive;

/**
 * Bring over files (specs/connect/12, Sprint C5a-2). SkySlope's and Lone Wolf's APIs don't hand out documents, so the
 * brokerage asks the vendor for its file export (a ZIP with a folder per transaction) and uploads it once. Each file
 * is filed into the deal whose street address its folder names; folders that match no deal, or more than one, are
 * listed for the broker instead of guessed.
 *
 * Safe with any ZIP: only PDFs, JPEGs and PNGs are kept (checked from their bytes), each up to 25 MB, at most 20,000
 * files and 4 GB unpacked, and nothing is ever written to disk under the ZIP's own paths.
 */
final class BringOverFiles
{
    public const MAX_FILES = 20000;

    public const MAX_UNPACKED = 4 * 1024 * 1024 * 1024;

    private const WORDS = ['street' => 'st', 'drive' => 'dr', 'avenue' => 'ave', 'av' => 'ave', 'road' => 'rd', 'lane' => 'ln', 'court' => 'ct', 'boulevard' => 'blvd',
        'place' => 'pl', 'circle' => 'cir', 'parkway' => 'pkwy', 'highway' => 'hwy', 'terrace' => 'ter', 'trail' => 'trl', 'way' => 'wy', 'north' => 'n', 'south' => 's',
        'east' => 'e', 'west' => 'w'];

    public function __construct(private readonly Documents $documents) {}

    /**
     * Reads one uploaded ZIP and files what it can. The ZIP is deleted afterwards either way.
     *
     * @return array{files: int, filed: int, deals: int, unmatched: list<string>}
     */
    public function run(int $importId): array
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('tx_file_imports')->where('id', $importId)->first();
        if ($row === null || $row->status !== 'queued' || ! is_string($row->path)) {
            throw new RuntimeException('Nothing to bring over.');
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open(Storage::disk('local')->path($row->path), ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('That file isn\'t a ZIP we can open.');
            }
            $result = $this->read($zip);
            $zip->close();
        } finally {
            Storage::disk('local')->delete($row->path);
        }
        $db->table('tx_file_imports')->where('id', $importId)->update(['status' => 'done', 'path' => null, 'files' => $result['files'], 'filed' => $result['filed'],
            'deals' => $result['deals'], 'unmatched' => json_encode($result['unmatched']), 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.files.brought_over', metadata: ['file' => $row->filename, 'filed' => $result['filed'], 'deals' => $result['deals']]);

        return $result;
    }

    /** @return array{files: int, filed: int, deals: int, unmatched: list<string>} */
    private function read(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException('That ZIP has more than 20,000 files. Split the export into smaller parts.');
        }
        $unpacked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $unpacked += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        if ($unpacked > self::MAX_UNPACKED) {
            throw new RuntimeException('That ZIP unpacks to more than 4 GB. Split the export into smaller parts.');
        }
        $deals = $this->addresses();
        $files = 0;
        $filed = 0;
        $touched = [];
        $unmatched = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) $stat['name'] : '';
            if ($name === '' || str_ends_with($name, '/') || str_contains($name, '__MACOSX/') || ! preg_match('/\.(pdf|jpe?g|png)$/i', $name)) {
                continue;
            }
            $files++;
            if ((int) $stat['size'] > Documents::MAX_BYTES) {
                continue;
            }
            $folders = array_slice(explode('/', str_replace('\\', '/', $name)), 0, -1);
            $deal = self::match($folders, $deals);
            if ($deal === null) {
                $folder = implode('/', $folders) !== '' ? implode('/', $folders) : '(top level)';
                $unmatched[$folder] = true;

                continue;
            }
            $bytes = $zip->getFromIndex($i);
            $model = Deal::query()->find($deal);
            if ($bytes !== false && $model !== null && $this->documents->imported($model, basename($name), $bytes) !== null) {
                $filed++;
                $touched[$deal] = true;
            }
        }

        return ['files' => $files, 'filed' => $filed, 'deals' => count($touched), 'unmatched' => array_slice(array_keys($unmatched), 0, 200)];
    }

    /**
     * The deal a file's folders name, looking from the innermost folder out. A folder that names more than one
     * deal (two deals at the same address) is not guessed.
     *
     * @param  list<string>  $folders
     * @param  array<int, string>  $deals  deal id => normalised street
     */
    public static function match(array $folders, array $deals): ?int
    {
        foreach (array_reverse($folders) as $folder) {
            $text = ' '.self::normalise($folder).' ';
            if (preg_match('/\d/', $text) !== 1) {
                continue;
            }
            $found = array_keys(array_filter($deals, fn (string $street): bool => $street !== '' && str_contains($text, ' '.$street.' ')));
            if (count($found) === 1) {
                return $found[0];
            }
            if (count($found) > 1) {
                return null;
            }
        }

        return null;
    }

    /** "44 Pecan Drive, #4" → "44 pecan dr 4": lower case, words shortened the way addresses usually are. */
    public static function normalise(string $text): string
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $w): string => self::WORDS[$w] ?? $w, $words));
    }

    /** @return array<int, string> every deal's street (without its unit), normalised */
    private function addresses(): array
    {
        $out = [];
        foreach (Deal::query()->get(['id', 'street']) as $deal) {
            $out[$deal->id] = self::normalise((string) preg_replace('/\s*#.*$/', '', $deal->street));
        }

        return $out;
    }
}
