<?php

declare(strict_types=1);

namespace Modules\Ledger\Audit;

/** CSV text with cells protected against spreadsheet formulas. */
final class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, string|int|null>>  $rows
     */
    public static function make(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'w+');
        assert($out !== false);
        fputcsv($out, $header, escape: '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (string|int|null $v): string => self::safe($v === null ? '' : (string) $v), $row), escape: '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').number_format(abs($cents) / 100, 2, '.', '');
    }

    private static function safe(string $value): string
    {
        return in_array(substr($value, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
