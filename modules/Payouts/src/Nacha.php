<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Carbon\CarbonImmutable;

/**
 * A standard NACHA ACH credit file (PPD), 94-character records, padded to a block of 10, for the
 * brokerage to upload to its own online banking. Credits only; the bank debits the brokerage's account.
 */
final class Nacha
{
    /**
     * @param  array{company_name: string, company_id: string, odfi_routing: string, bank_name: string}  $originator
     * @param  list<array{name: string, routing: string, account: string, type: string, cents: int, id: string}>  $entries
     */
    public static function build(array $originator, array $entries, CarbonImmutable $effective, CarbonImmutable $now, string $fileId = 'A'): string
    {
        $odfi = $originator['odfi_routing'];
        $lines = [];
        $lines[] = '1'.'01'.' '.$odfi.self::pad($originator['company_id'], 10, true).$now->format('ymdHi').$fileId.'094'.'10'.'1'
            .self::pad(mb_strtoupper($originator['bank_name']), 23).self::pad(mb_strtoupper($originator['company_name']), 23).self::pad('', 8);
        $lines[] = '5'.'220'.self::pad(mb_strtoupper($originator['company_name']), 16).self::pad('', 20).self::pad($originator['company_id'], 10)
            .'PPD'.self::pad('COMMISSION', 10).self::pad('', 6).$effective->format('ymd').'   '.'1'.substr($odfi, 0, 8).'0000001';

        $hash = 0;
        $total = 0;
        foreach ($entries as $i => $e) {
            $code = $e['type'] === 'savings' ? '32' : '22';
            $lines[] = '6'.$code.substr($e['routing'], 0, 8).substr($e['routing'], 8, 1).self::pad($e['account'], 17)
                .str_pad((string) $e['cents'], 10, '0', STR_PAD_LEFT).self::pad(mb_substr($e['id'], 0, 15), 15)
                .self::pad(mb_strtoupper(self::ascii($e['name'])), 22).'  '.'0'.substr($odfi, 0, 8).str_pad((string) ($i + 1), 7, '0', STR_PAD_LEFT);
            $hash += (int) substr($e['routing'], 0, 8);
            $total += $e['cents'];
        }
        $hashText = str_pad(substr((string) $hash, -10), 10, '0', STR_PAD_LEFT);
        $count = count($entries);
        $lines[] = '8'.'220'.str_pad((string) $count, 6, '0', STR_PAD_LEFT).$hashText.str_pad('0', 12, '0', STR_PAD_LEFT)
            .str_pad((string) $total, 12, '0', STR_PAD_LEFT).self::pad($originator['company_id'], 10).self::pad('', 19).self::pad('', 6).substr($odfi, 0, 8).'0000001';
        $records = count($lines) + 1;
        $blocks = (int) ceil($records / 10);
        $lines[] = '9'.'000001'.str_pad((string) $blocks, 6, '0', STR_PAD_LEFT).str_pad((string) $count, 8, '0', STR_PAD_LEFT).$hashText
            .str_pad('0', 12, '0', STR_PAD_LEFT).str_pad((string) $total, 12, '0', STR_PAD_LEFT).self::pad('', 39);
        while (count($lines) % 10 !== 0) {
            $lines[] = str_repeat('9', 94);
        }

        return implode("\n", $lines)."\n";
    }

    private static function pad(string $value, int $length, bool $left = false): string
    {
        $value = mb_substr($value, 0, $length);

        return $left ? str_pad($value, $length, ' ', STR_PAD_LEFT) : str_pad($value, $length);
    }

    private static function ascii(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9 .,\-]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $value)) ?? '';
    }
}
