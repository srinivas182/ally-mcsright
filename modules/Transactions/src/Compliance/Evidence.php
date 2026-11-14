<?php

declare(strict_types=1);

namespace Modules\Transactions\Compliance;

use Illuminate\Support\Facades\Storage;
use Modules\Forms\Pdf;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Reading\SafeCopy;
use setasign\Fpdi\Fpdi;

/**
 * Shows the evidence for a finding: the cited page as a one-page PDF with the quoted words boxed in red, so the
 * reviewer sees exactly what the scan saw. Works for text pages and for scans (via OCR word positions).
 */
final class Evidence
{
    public static function page(Document $doc, int $page, string $quote): string
    {
        $reading = DocumentReading::query()->where('document_id', $doc->id)->first();
        $method = ((array) ($reading->methods ?? []))[$page - 1] ?? 'text';
        $pdf = Pdf::normalise((string) Storage::disk('local')->get($doc->path))[0];
        $file = Pdf::tempFile('evidence-', $pdf);
        $png = null;
        try {
            $out = new Fpdi('P', 'pt');
            $out->SetAutoPageBreak(false);
            $count = $out->setSourceFile($file);
            $page = max(1, min($page, $count));
            $tpl = $out->importPage($page);
            $size = $out->getTemplateSize($tpl);
            [$w, $h] = is_array($size) ? [(float) $size['width'], (float) $size['height']] : [612.0, 792.0];
            $out->AddPage($w > $h ? 'L' : 'P', [$w, $h]);
            if ($method === 'text') {
                $out->useTemplate($tpl);
                $words = SafeCopy::textWords($file, $page);
            } else {
                $png = SafeCopy::render($file, $page);
                $png !== null ? $out->Image($png, 0, 0, $w, $h) : $out->useTemplate($tpl);
                $words = $png !== null ? SafeCopy::ocrWords($png, $w) : [];
            }
            $box = self::find($words, $quote);
            if ($box !== null) {
                $out->SetDrawColor(220, 38, 38);
                $out->SetLineWidth(2);
                $out->Rect($box[0] - 3, $box[1] - 3, $box[2] + 6, $box[3] + 6);
            }

            return $out->Output('S');
        } finally {
            @unlink($file);
            if ($png !== null) {
                @unlink($png);
            }
        }
    }

    /**
     * The box around the words of the quote, found in order on the page (ignoring case and punctuation).
     *
     * @param  list<array{0: string, 1: array{0: float, 1: float, 2: float, 3: float}}>  $words
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function find(array $words, string $quote): ?array
    {
        $norm = fn (string $s): string => (string) preg_replace('/[^a-z0-9$]/', '', mb_strtolower($s));
        $want = array_values(array_filter(array_map($norm, preg_split('/\s+/', $quote) ?: []), fn (string $t): bool => $t !== ''));
        $have = array_map(fn (array $w): string => $norm($w[0]), $words);
        if ($want === []) {
            return null;
        }
        for ($i = 0; $i + count($want) <= count($have); $i++) {
            if (array_slice($have, $i, count($want)) === $want) {
                [$x1, $y1, $x2, $y2] = [INF, INF, -INF, -INF];
                foreach (array_slice($words, $i, count($want)) as [, $b]) {
                    [$x1, $y1, $x2, $y2] = [min($x1, $b[0]), min($y1, $b[1]), max($x2, $b[0] + $b[2]), max($y2, $b[1] + $b[3])];
                }

                return [$x1, $y1, $x2 - $x1, $y2 - $y1];
            }
        }

        return null;
    }
}
