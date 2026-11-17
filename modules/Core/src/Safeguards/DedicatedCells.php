<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Throwable;

/**
 * Dedicated databases (DS2, specs/core/12). A dedicated database is a cell with one brokerage: same schema, same
 * releases. Its connection details are stored encrypted and never shown again; the connection is registered at
 * runtime as "cell_{id}" the first time a request for that brokerage needs it (CellRouting resolver).
 */
final class DedicatedCells
{
    public const DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    /**
     * @param  array{driver?: string, host?: string, port?: int|string, database?: string, username?: string, password?: string, region?: string}  $details
     */
    public function create(Tenant $tenant, array $details): Cell
    {
        $driver = (string) ($details['driver'] ?? 'mysql');
        if (! in_array($driver, self::DRIVERS, true) || trim((string) ($details['database'] ?? '')) === '') {
            throw ValidationException::withMessages(['database' => 'Enter the database name (and host, user and password for MySQL).']);
        }
        if ($driver !== 'sqlite' && (trim((string) ($details['host'] ?? '')) === '' || trim((string) ($details['username'] ?? '')) === '')) {
            throw ValidationException::withMessages(['host' => 'Enter the host and user.']);
        }
        $creds = ['driver' => $driver, 'host' => (string) ($details['host'] ?? ''), 'port' => (int) ($details['port'] ?? 3306), 'database' => (string) ($details['database'] ?? ''),
            'username' => (string) ($details['username'] ?? ''), 'password' => (string) ($details['password'] ?? '')];
        $cell = Cell::query()->create(['name' => 'Dedicated: '.$tenant->slug, 'region' => (string) ($details['region'] ?? 'us-east'), 'status' => CellStatus::Full,
            'db_connection' => 'dedicated', 'capacity_tenants' => 1, 'capacity_agents' => 100000]);
        $cell->forceFill(['dedicated' => true, 'dedicated_tenant_id' => $tenant->id, 'credentials' => Crypt::encryptString((string) json_encode($creds))])->save();
        app(AuditLogger::class)->record('safeguards.cell.created', metadata: ['cell' => $cell->id, 'tenant' => $tenant->id, 'driver' => $driver], severity: 'notice');

        return $cell;
    }

    /** Registers the cell's connection (once per process) and returns its name, or null for a shared cell. */
    public static function register(Cell $cell): ?string
    {
        if (! (bool) $cell->getAttribute('dedicated') || ! is_string($cell->getAttribute('credentials'))) {
            return null;
        }
        $name = 'cell_'.$cell->id;
        if (! is_array(config('database.connections.'.$name))) {
            $c = (array) json_decode(Crypt::decryptString((string) $cell->getAttribute('credentials')), true);
            $driver = (string) ($c['driver'] ?? 'mysql');
            $base = (array) config('database.connections.'.($driver === 'sqlite' ? 'sqlite' : 'mysql'), []);
            config(['database.connections.'.$name => array_merge($base, $driver === 'sqlite'
                ? ['driver' => 'sqlite', 'database' => (string) $c['database'], 'foreign_key_constraints' => true]
                : ['driver' => $driver, 'host' => $c['host'], 'port' => (int) $c['port'], 'database' => $c['database'], 'username' => $c['username'], 'password' => $c['password']])]);
        }

        return $name;
    }

    /**
     * Reachable, a supported version, and holding nothing of this brokerage yet.
     *
     * @return array{ok: bool, checks: list<array{label: string, ok: bool, detail: string}>}
     */
    public function test(Cell $cell, Tenant $tenant): array
    {
        $name = self::register($cell) ?? $cell->db_connection;
        $checks = [];
        try {
            $conn = DB::connection($name);
            $conn->getPdo();
            $checks[] = ['label' => 'Reachable', 'ok' => true, 'detail' => 'Connected.'];
            $driver = $conn->getDriverName();
            $version = $driver === 'sqlite' ? (string) $conn->selectOne('select sqlite_version() as v')->v : (string) $conn->selectOne('select version() as v')->v;
            $supported = $driver === 'sqlite' || version_compare((string) preg_replace('/[^0-9.].*$/', '', $version), '8.0', '>=') || str_contains(strtolower($version), 'mariadb');
            $checks[] = ['label' => 'Supported version', 'ok' => $supported, 'detail' => $driver.' '.$version];
            $holding = 0;
            foreach (Structure::tableNames($conn) as $t) {
                if ($conn->getSchemaBuilder()->hasColumn($t, 'tenant_id')) {
                    $holding += $conn->table($t)->where('tenant_id', $tenant->id)->count();
                }
            }
            $checks[] = ['label' => 'Empty for this brokerage', 'ok' => $holding === 0, 'detail' => $holding === 0 ? 'Nothing of this brokerage there yet.' : "{$holding} rows already there."];
        } catch (Throwable $e) {
            $checks[] = ['label' => 'Reachable', 'ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 160)];
        }

        return ['ok' => collect($checks)->every(fn (array $c): bool => $c['ok']), 'checks' => $checks];
    }
}
