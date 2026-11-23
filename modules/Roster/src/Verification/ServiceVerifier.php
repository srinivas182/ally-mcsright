<?php

declare(strict_types=1);

namespace Modules\Roster\Verification;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use RuntimeException;

/**
 * Licence look-up through the provider HQ chose in Outside services → Agent licence look-up (LN4):
 *
 * - State public licence data (recommended, free): the licence lists states publish, loaded by HQ with
 *   `php artisan roster:licence-data {state} {file}`. A state without a loaded list answers "unknown", so it is
 *   verified by hand rather than flagged.
 * - ARELLO: needs ARELLO's data agreement and API specification; until then it behaves like "Typed in by hand".
 * - Typed in by hand: no automatic checks; staff verify.
 */
final class ServiceVerifier implements LicenceVerifier
{
    public function configured(): bool
    {
        return (app(OutsideServices::class)->active('licence_lookup')['provider'] ?? null) === 'state_data';
    }

    public function method(): string
    {
        return 'state_data';
    }

    public function lookup(string $state, string $number, string $lastName): array
    {
        $db = DB::connection(Connections::control());
        $state = strtoupper($state);
        if (! $db->table('licence_records')->where('state', $state)->exists()) {
            return ['status' => 'unknown', 'expires_on' => null, 'last_name' => null];
        }
        $norm = fn (string $n): string => ltrim(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $n)), '0');
        $row = $db->table('licence_records')->where('state', $state)->where('number', $norm($number))->first();
        if ($row === null) {
            return ['status' => 'not_found', 'expires_on' => null, 'last_name' => null];
        }
        $expired = $row->expires_on !== null && (string) $row->expires_on < now()->toDateString();

        return ['status' => $expired ? 'expired' : (string) $row->status, 'expires_on' => $row->expires_on !== null ? substr((string) $row->expires_on, 0, 10) : null,
            'last_name' => $row->last_name !== null ? (string) $row->last_name : null];
    }

    /**
     * Loads a state's published licence list (CSV text). See loadFile().
     */
    public static function load(string $state, string $csv): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lic');
        file_put_contents($tmp, $csv);
        try {
            return self::loadFile($state, $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Loads a state's published licence list from a CSV file, read a row at a time (the Texas list has hundreds of
     * thousands of licences). Columns are found by name (licence number, last name or name, status, expiration), or
     * given by position for files without a header row ($columns: number, name, status, expires). The state's earlier
     * list is replaced only when the new one loads. Returns how many licences were loaded.
     *
     * @param  array{number: int, name: int, status: int, expires: int|null}|null  $columns
     */
    public static function loadFile(string $state, string $path, ?array $columns = null): int
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return 0;
        }
        $first = fgetcsv($fh, 0, ',', '"', '');
        if (! is_array($first)) {
            fclose($fh);

            return 0;
        }
        $num = $last = $name = $status = $exp = null;
        $header = true;
        if ($columns !== null) {
            ['number' => $num, 'name' => $name, 'status' => $status, 'expires' => $exp] = $columns;
            // A file given by position may still start with a header row: skip it when its number column is not a number.
            $header = preg_match('/\d/', (string) ($first[$num] ?? '')) !== 1;
        } else {
            $head = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $first);
            // Each name is tried across all columns before the next, so "license number" wins over "phone number".
            $col = function (array $names) use ($head): ?int {
                foreach ($names as $n) {
                    foreach ($head as $i => $h) {
                        if ($h === $n || str_contains($h, $n)) {
                            return $i;
                        }
                    }
                }

                return null;
            };
            $num = $col(['license number', 'licence number', 'license_number', 'licensenumber', 'license #', 'lic #', 'license no', 'number']);
            $last = $col(['last name', 'last_name', 'lastname', 'surname']);
            $name = $col(['full name', 'full_name', 'licensee name', 'licensee', 'name']);
            $status = $col(['primary status', 'license status', 'status']);
            $exp = $col(['expiration', 'expiry', 'expire']);
        }
        if ($num === null || $status === null) {
            fclose($fh);

            return 0;
        }
        $state = strtoupper($state);
        $now = now();
        $db = DB::connection(Connections::control());
        try {
            $db->transaction(function () use ($db, $fh, $first, $header, $num, $last, $name, $status, $exp, $state, $now): void {
                $db->table('licence_records')->where('state', $state)->delete();
                $batch = [];
                $total = 0;
                $row = $header ? fgetcsv($fh, 0, ',', '"', '') : $first;
                while (is_array($row)) {
                    $record = self::record($row, $num, $last, $name, $status, $exp, $state, $now);
                    if ($record !== null) {
                        $batch[$record['number']] = $record;
                    }
                    $row = fgetcsv($fh, 0, ',', '"', '');
                    if (count($batch) >= 1000 || ($row === false && $batch !== [])) {
                        $db->table('licence_records')->insertOrIgnore(array_values($batch));
                        $total += count($batch);
                        $batch = [];
                    }
                }
                if ($total === 0) {
                    throw new RuntimeException('No licences in the file.'); // keep the state's last list
                }
            });
        } catch (RuntimeException) {
            fclose($fh);

            return 0;
        }
        fclose($fh);

        return $db->table('licence_records')->where('state', $state)->count();
    }

    /**
     * One licence from a row of a state's list, or null when the row has no licence number.
     *
     * @param  array<int, string|null>  $row
     * @return array{state: string, number: string, last_name: string|null, full_name: string|null, status: string, expires_on: string|null, loaded_at: \DateTimeInterface}|null
     */
    private static function record(array $row, int $num, ?int $last, ?int $name, int $status, ?int $exp, string $state, \DateTimeInterface $now): ?array
    {
        $n = ltrim(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($row[$num] ?? ''))), '0');
        if ($n === '') {
            return null;
        }
        $full = $name !== null ? trim((string) ($row[$name] ?? '')) : '';
        $lastName = $last !== null ? trim((string) ($row[$last] ?? '')) : (str_contains($full, ',') ? trim(explode(',', $full)[0]) : (string) collect(explode(' ', $full))->last());
        $s = mb_strtolower(trim((string) ($row[$status] ?? '')));
        $expires = null;
        if ($exp !== null && trim((string) ($row[$exp] ?? '')) !== '') {
            $expires = rescue(fn (): string => CarbonImmutable::parse((string) $row[$exp])->toDateString(), null, false);
        }

        return ['state' => $state, 'number' => mb_substr($n, 0, 40), 'last_name' => $lastName !== '' ? mb_substr($lastName, 0, 80) : null,
            'full_name' => $full !== '' ? mb_substr($full, 0, 160) : null,
            'status' => match (true) {
                str_contains($s, 'suspend') || str_contains($s, 'revok') => 'suspended', str_contains($s, 'expir') => 'expired',
                str_contains($s, 'inactive') || str_contains($s, 'cancel') || str_contains($s, 'null') || str_contains($s, 'void') || str_contains($s, 'deceas') || str_contains($s, 'relinq') => 'inactive',
                default => 'active',
            }, 'expires_on' => $expires, 'loaded_at' => $now];
    }
}
