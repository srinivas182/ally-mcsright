<?php

declare(strict_types=1);

namespace Modules\Roster\Verification;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;

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
     * Loads a state's published licence list (CSV). Columns are found by name: licence/license number, last name (or
     * name), status, expiration. Returns how many licences were loaded. The state's earlier list is replaced.
     */
    public static function load(string $state, string $csv): int
    {
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\n/', trim($csv)) ?: []), fn ($r) => count($r) > 1));
        if (count($rows) < 2) {
            return 0;
        }
        $head = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0]);
        $col = function (array $names) use ($head): ?int {
            foreach ($head as $i => $h) {
                foreach ($names as $n) {
                    if (str_contains($h, $n)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $num = $col(['license number', 'licence number', 'license #', 'lic #', 'license_number', 'number']);
        $last = $col(['last name', 'last_name', 'surname']);
        $name = $col(['full name', 'licensee', 'name']);
        $status = $col(['status']);
        $exp = $col(['expir']);
        if ($num === null || $status === null) {
            return 0;
        }
        $state = strtoupper($state);
        $now = now();
        $db = DB::connection(Connections::control());
        $db->transaction(function () use ($db, $rows, $num, $last, $name, $status, $exp, $state, $now): void {
            $db->table('licence_records')->where('state', $state)->delete();
            foreach (array_chunk(array_slice($rows, 1), 1000) as $chunk) {
                $insert = [];
                foreach ($chunk as $r) {
                    $n = ltrim(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($r[$num] ?? ''))), '0');
                    if ($n === '') {
                        continue;
                    }
                    $full = $name !== null ? trim((string) ($r[$name] ?? '')) : '';
                    $lastName = $last !== null ? trim((string) ($r[$last] ?? '')) : (str_contains($full, ',') ? trim(explode(',', $full)[0]) : (string) collect(explode(' ', $full))->last());
                    $s = mb_strtolower(trim((string) ($r[$status] ?? '')));
                    $expires = null;
                    if ($exp !== null && trim((string) ($r[$exp] ?? '')) !== '') {
                        $expires = rescue(fn (): string => CarbonImmutable::parse((string) $r[$exp])->toDateString(), null, false);
                    }
                    $insert[$n] = ['state' => $state, 'number' => mb_substr($n, 0, 40), 'last_name' => $lastName !== '' ? mb_substr($lastName, 0, 80) : null,
                        'full_name' => $full !== '' ? mb_substr($full, 0, 160) : null,
                        'status' => match (true) {
                            str_contains($s, 'suspend') || str_contains($s, 'revok') => 'suspended', str_contains($s, 'expir') => 'expired',
                            str_contains($s, 'inactive') || str_contains($s, 'cancel') => 'inactive', default => 'active',
                        }, 'expires_on' => $expires, 'loaded_at' => $now];
                }
                if ($insert !== []) {
                    $db->table('licence_records')->insert(array_values($insert));
                }
            }
        });

        return $db->table('licence_records')->where('state', $state)->count();
    }
}
