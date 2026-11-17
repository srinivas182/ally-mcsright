<?php

declare(strict_types=1);

namespace Modules\Setup\Imports;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Turns the first sheet of an Excel file (.xlsx) into CSV, so exports can be dropped in as they come (SU1). Reads the
 * workbook's XML directly; formulas are read as their saved values, never run.
 */
final class Spreadsheet
{
    public const MAX_ROWS = 50_000;

    public static function toCsv(string $xlsx, string $csv): void
    {
        $zip = new ZipArchive;
        if ($zip->open($xlsx) !== true) {
            throw new RuntimeException('This Excel file could not be opened. Save it as CSV and try again.');
        }
        $strings = [];
        $shared = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($shared)) {
            $xml = self::xml($shared);
            foreach ($xml->si as $si) {
                $strings[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r): string => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (! is_string($sheet)) {
            throw new RuntimeException('This Excel file has no first sheet. Save it as CSV and try again.');
        }
        $out = fopen($csv, 'w');
        if ($out === false) {
            throw new RuntimeException('Could not write the file.');
        }
        $n = 0;
        foreach (self::xml($sheet)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = self::column((string) $c['r']);
                $type = (string) $c['t'];
                $value = $type === 's' ? ($strings[(int) $c->v] ?? '') : ($type === 'inlineStr' ? (string) $c->is->t : (string) $c->v);
                $cells[$col] = $value;
            }
            if ($cells === []) {
                continue;
            }
            $line = [];
            for ($i = 0; $i <= max(array_keys($cells)); $i++) {
                $line[] = $cells[$i] ?? '';
            }
            fputcsv($out, $line, escape: '\\');
            if (++$n >= self::MAX_ROWS) {
                break;
            }
        }
        fclose($out);
    }

    private static function xml(string $text): SimpleXMLElement
    {
        $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('This Excel file could not be read. Save it as CSV and try again.');
        }

        return $xml;
    }

    /** "C7" → 2 */
    private static function column(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
