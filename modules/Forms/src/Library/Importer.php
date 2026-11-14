<?php

declare(strict_types=1);

namespace Modules\Forms\Library;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Forms\Templates;

/**
 * Imports forms from other systems (dotloop, SkySlope, zipForm), an association's PDF package, or any fillable
 * PDFs: single PDFs or a ZIP. Fillable fields are placed and mapped automatically; anything uncertain is left for
 * review in the visual editor. ZIP entries are read in memory (never written by their own path), only PDFs are
 * taken, and sizes are capped.
 */
final class Importer
{
    public const SOURCES = ['dotloop' => 'dotloop', 'skyslope' => 'SkySlope', 'zipform' => 'zipForm (Lone Wolf)', 'association' => 'Association package', 'pdf' => 'Other fillable PDFs'];

    private const MAX_FILES = 50;

    private const MAX_PDF_BYTES = 20 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 200 * 1024 * 1024;

    public function __construct(private readonly Templates $templates) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{name: string, template: string, fields: int, filled_automatically: int, needs_signer: int}>
     */
    public function import(array $files, string $source, User $by): array
    {
        if (! Templates::manages($by)) {
            throw ValidationException::withMessages(['forms' => 'Managing forms needs the "Manage forms" permission.']);
        }
        if (! isset(self::SOURCES[$source])) {
            throw ValidationException::withMessages(['source' => 'Choose where the forms come from.']);
        }
        $pdfs = [];
        foreach ($files as $file) {
            $bytes = (string) file_get_contents((string) $file->getRealPath());
            if (str_starts_with($bytes, 'PK')) {
                foreach (self::unzip($bytes) as $name => $pdf) {
                    $pdfs[$name] = $pdf;
                }
            } elseif (str_starts_with($bytes, '%PDF')) {
                $pdfs[$file->getClientOriginalName()] = $bytes;
            } else {
                throw ValidationException::withMessages(['files' => $file->getClientOriginalName().' is not a PDF or a ZIP of PDFs.']);
            }
        }
        if ($pdfs === []) {
            throw ValidationException::withMessages(['files' => 'No PDF forms found.']);
        }
        if (count($pdfs) > self::MAX_FILES) {
            throw ValidationException::withMessages(['files' => 'Import up to '.self::MAX_FILES.' forms at a time.']);
        }
        $report = [];
        foreach ($pdfs as $name => $pdf) {
            $fields = FieldMapper::map(FillableFields::read($pdf), Libraries::sizes($pdf));
            $ulid = $this->templates->fromPdf(self::title((string) $name), $pdf, $fields, $by, [
                'source' => $source === 'association' ? 'association' : 'brokerage', 'import_source' => $source,
            ]);
            $report[] = ['name' => self::title((string) $name), 'template' => $ulid, 'fields' => count($fields),
                'filled_automatically' => count(array_filter($fields, fn (array $f): bool => $f['bind'] !== null)),
                'needs_signer' => count(array_filter($fields, fn (array $f): bool => $f['role'] === FieldMapper::UNASSIGNED))];
        }

        return $report;
    }

    /** "TREC_20-17_One-to-Four-Family.pdf" → "TREC 20-17 One-to-Four-Family". */
    public static function title(string $filename): string
    {
        $base = pathinfo(str_replace('\\', '/', $filename), PATHINFO_FILENAME);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', str_replace('_', ' ', $base))), 0, 160) ?: 'Imported form';
    }

    /**
     * @return array<string, string> file name => PDF bytes
     */
    private static function unzip(string $bytes): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw ValidationException::withMessages(['files' => 'ZIP files cannot be opened on this server; upload the PDFs instead.']);
        }
        $file = tempnam(sys_get_temp_dir(), 'zip');
        if ($file === false) {
            return [];
        }
        file_put_contents($file, $bytes);
        $zip = new \ZipArchive;
        if ($zip->open($file) !== true) {
            @unlink($file);
            throw ValidationException::withMessages(['files' => 'That ZIP file could not be opened.']);
        }
        $out = [];
        $total = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if ($stat === false || str_ends_with($name, '/') || str_starts_with(basename($name), '.') || str_contains($name, '__MACOSX')
                    || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                    continue;
                }
                $size = (int) $stat['size'];
                $total += $size;
                if ($size > self::MAX_PDF_BYTES || $total > self::MAX_TOTAL_BYTES || count($out) >= self::MAX_FILES) {
                    throw ValidationException::withMessages(['files' => 'The ZIP is too large: up to '.self::MAX_FILES.' PDFs of 20 MB each.']);
                }
                $pdf = (string) $zip->getFromIndex($i);
                if (str_starts_with($pdf, '%PDF')) {
                    $out[basename($name)] = $pdf;
                }
            }
        } finally {
            $zip->close();
            @unlink($file);
        }

        return $out;
    }
}
