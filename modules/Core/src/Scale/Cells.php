<?php

declare(strict_types=1);

namespace Modules\Core\Scale;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Safeguards\BrokerageExport;
use Modules\Core\Safeguards\DedicatedCells;
use Modules\Core\Safeguards\Structure;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CellRouting;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Models\Cell;
use Throwable;

/**
 * Cell automation (PS1, specs/core/14). Brokerages live in shared cells (databases), each with soft limits for
 * brokerages and agents. RightAlly watches every cell:
 *
 * - at 85% of either limit HQ is told to add a cell (once a day);
 * - at 100% the cell closes itself to new brokerages (status full, marked "automatic"), and reopens on its own when
 *   it drops below 75%; a cell a person closed is never reopened automatically;
 * - when no shared cell has room HQ gets an urgent alert, because new signups would fail.
 *
 * Adding a cell: HQ enters the database details (stored encrypted, never shown again). RightAlly connects, copies
 * the table structure from the main cell, checks it, and only then opens the cell to new brokerages.
 */
#[CrossTenantAccess('Counts brokerages and agents per cell and emails RightAlly HQ staff')]
final class Cells
{
    public const WARN_PERCENT = 85;

    public const REOPEN_PERCENT = 75;

    public function __construct(private readonly CellRouting $routing) {}

    /**
     * Every cell with how full it is.
     *
     * @return list<array{id: int, ulid: string, name: string, region: string, status: string, dedicated: bool, auto_full: bool, tenants: int, capacity_tenants: int, agents: int, capacity_agents: int, percent: int, checked_at: string|null, last_check: array<string, mixed>|null}>
     */
    public function usage(): array
    {
        $tenants = DB::connection(Connections::control())->table('tenants')->whereNotIn('status', ['closed'])->groupBy('cell_id')->selectRaw('cell_id, count(*) as n')->pluck('n', 'cell_id');
        $agents = CrossTenant::query(User::class, $this)->where('users.kind', UserKind::Agent)->where('users.status', 'active')
            ->join('tenants', 'tenants.id', '=', 'users.tenant_id')->groupBy('tenants.cell_id')->selectRaw('tenants.cell_id as cell, count(*) as n')->pluck('n', 'cell');
        $out = [];
        foreach (Cell::query()->orderBy('id')->get() as $cell) {
            $t = (int) ($tenants[$cell->id] ?? 0);
            $a = (int) ($agents[$cell->id] ?? 0);
            $out[] = ['id' => $cell->id, 'ulid' => $cell->ulid, 'name' => $cell->name, 'region' => $cell->region, 'status' => $cell->status->value, 'dedicated' => (bool) $cell->getAttribute('dedicated'),
                'auto_full' => (bool) $cell->getAttribute('auto_full'), 'tenants' => $t, 'capacity_tenants' => $cell->capacity_tenants, 'agents' => $a, 'capacity_agents' => $cell->capacity_agents,
                'percent' => self::percent($t, $cell->capacity_tenants, $a, $cell->capacity_agents),
                'checked_at' => $cell->getAttribute('checked_at') !== null ? (string) $cell->getAttribute('checked_at') : null,
                'last_check' => is_string($cell->getAttribute('last_check')) ? (array) json_decode((string) $cell->getAttribute('last_check'), true) : null];
        }

        return $out;
    }

    /**
     * Every 10 minutes: close full cells, reopen ones that have room again, warn HQ early.
     *
     * @return array{closed: list<string>, reopened: list<string>, warned: list<string>, no_room: bool}
     */
    public function watch(): array
    {
        $out = ['closed' => [], 'reopened' => [], 'warned' => [], 'no_room' => false];
        foreach ($this->usage() as $u) {
            if ($u['dedicated']) {
                continue;
            }
            $cell = Cell::query()->findOrFail($u['id']);
            if ($u['status'] === CellStatus::Active->value && $u['percent'] >= 100) {
                $cell->forceFill(['status' => CellStatus::Full, 'auto_full' => true])->save();
                $out['closed'][] = $u['name'];
                app(AuditLogger::class)->record('scale.cell.auto_full', metadata: ['cell' => $u['id'], 'percent' => $u['percent']], severity: 'warning');
            } elseif ($u['status'] === CellStatus::Full->value && $u['auto_full'] && $u['percent'] < self::REOPEN_PERCENT) {
                $cell->forceFill(['status' => CellStatus::Active, 'auto_full' => false])->save();
                $out['reopened'][] = $u['name'];
                app(AuditLogger::class)->record('scale.cell.reopened', metadata: ['cell' => $u['id'], 'percent' => $u['percent']], severity: 'notice');
            }
            if ($u['percent'] >= self::WARN_PERCENT && ($cell->getAttribute('warned_at') === null || now()->diffInHours($cell->getAttribute('warned_at')) >= 24)) {
                $cell->forceFill(['warned_at' => now()])->save();
                $out['warned'][] = "{$u['name']} is {$u['percent']}% full ({$u['tenants']} of {$u['capacity_tenants']} brokerages, {$u['agents']} of {$u['capacity_agents']} agents).";
            }
        }
        $out['no_room'] = ! Cell::query()->where('status', CellStatus::Active)->where('dedicated', false)->withCount('tenants')->get()
            ->contains(fn (Cell $c): bool => (int) $c->tenants_count < $c->capacity_tenants);
        $lines = [...$out['warned'], ...array_map(fn (string $n): string => "{$n} is full and closed itself to new brokerages.", $out['closed'])];
        if ($out['no_room'] || $lines !== []) {
            $hq = CrossTenant::query(User::class, $this)->where('kind', UserKind::HqStaff)->where('status', 'active')->get();
            Notification::send($hq, new CellsAlert($out['no_room'] ? 'No cell has room for new brokerages' : 'A cell is filling up',
                [...($out['no_room'] ? ['New brokerage signups will fail until a cell is added or reopened.'] : []), ...$lines, 'Add a cell in HQ › Cells.'], $out['no_room']));
        }

        return $out;
    }

    /**
     * Adds a shared cell. It stays in maintenance until prepare() has copied the structure and checked it.
     *
     * @param  array{name: string, region: string, driver?: string, host?: string, port?: int|string, database: string, username?: string, password?: string, capacity_tenants?: int, capacity_agents?: int}  $in
     */
    public function add(array $in, User $by): Cell
    {
        $driver = (string) ($in['driver'] ?? 'mysql');
        if (! in_array($driver, DedicatedCells::DRIVERS, true) || trim($in['database']) === '' || ($driver !== 'sqlite' && trim((string) ($in['host'] ?? '')) === '')) {
            throw ValidationException::withMessages(['database' => 'Enter the host and database name.']);
        }
        if (Cell::query()->where('name', trim($in['name']))->exists()) {
            throw ValidationException::withMessages(['name' => 'A cell with this name already exists.']);
        }
        $cell = Cell::query()->create(['name' => mb_substr(trim($in['name']), 0, 60), 'region' => mb_substr(trim($in['region']), 0, 30), 'status' => CellStatus::Maintenance,
            'db_connection' => 'shared', 'capacity_tenants' => max(1, (int) ($in['capacity_tenants'] ?? 2000)), 'capacity_agents' => max(1, (int) ($in['capacity_agents'] ?? 50000))]);
        $cell->forceFill(['credentials' => Crypt::encryptString((string) json_encode(['driver' => $driver, 'host' => (string) ($in['host'] ?? ''), 'port' => (int) ($in['port'] ?? 3306),
            'database' => trim($in['database']), 'username' => (string) ($in['username'] ?? ''), 'password' => (string) ($in['password'] ?? '')]))])->save();
        app(AuditLogger::class)->record('scale.cell.added', metadata: ['cell' => $cell->id, 'name' => $cell->name, 'by' => $by->id], severity: 'notice');

        return $cell;
    }

    /**
     * Connects, copies the table structure from the main cell, and checks it. Opens the cell when everything passes.
     *
     * @return array{ok: bool, checks: list<array{label: string, ok: bool, detail: string}>}
     */
    public function prepare(Cell $cell, bool $open = true): array
    {
        $checks = [];
        try {
            $name = DedicatedCells::register($cell) ?? throw new \RuntimeException('No connection details for this cell.');
            $to = DB::connection($name);
            $to->getPdo();
            $checks[] = ['label' => 'Reachable', 'ok' => true, 'detail' => 'Connected.'];
            $r = Structure::sync(DB::connection(Connections::cell()), $to, BrokerageExport::tables());
            $checks[] = ['label' => 'Tables', 'ok' => true, 'detail' => "{$r['tables']} table(s) created, {$r['columns']} column(s) added."];
            $missing = array_values(array_diff(BrokerageExport::tables(), $to->getSchemaBuilder()->getTableListing(schemaQualified: false)));
            $checks[] = ['label' => 'Every table is there', 'ok' => $missing === [], 'detail' => $missing === [] ? 'Yes.' : 'Missing: '.implode(', ', array_slice($missing, 0, 5))];
            $to->select('select 1');
        } catch (Throwable $e) {
            $checks[] = ['label' => 'Connection', 'ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
        }
        $ok = ! in_array(false, array_column($checks, 'ok'), true);
        $cell->forceFill(['checked_at' => now(), 'last_check' => json_encode($checks)] + ($ok && $open && $cell->status === CellStatus::Maintenance ? ['status' => CellStatus::Active] : []))->save();
        $this->routing->flush();
        app(AuditLogger::class)->record('scale.cell.prepared', metadata: ['cell' => $cell->id, 'ok' => $ok], severity: $ok ? 'notice' : 'warning');

        return ['ok' => $ok, 'checks' => $checks];
    }

    /** HQ opens or closes a cell to new brokerages by hand (a hand-closed cell is never reopened automatically). */
    public function setStatus(Cell $cell, CellStatus $status, User $by): void
    {
        if ((bool) $cell->getAttribute('dedicated')) {
            throw ValidationException::withMessages(['cell' => 'A dedicated database holds one brokerage; its status is managed with that brokerage.']);
        }
        $passed = is_string($cell->getAttribute('last_check')) && ! in_array(false, array_column((array) json_decode((string) $cell->getAttribute('last_check'), true), 'ok'), true);
        if ($status === CellStatus::Active && $cell->getAttribute('credentials') !== null && ! $passed) {
            throw ValidationException::withMessages(['cell' => 'Prepare and check the cell before opening it.']);
        }
        $cell->forceFill(['status' => $status, 'auto_full' => false])->save();
        app(AuditLogger::class)->record('scale.cell.status', metadata: ['cell' => $cell->id, 'status' => $status->value, 'by' => $by->id], severity: 'notice');
    }

    public function setCapacity(Cell $cell, int $tenants, int $agents, User $by): void
    {
        $cell->forceFill(['capacity_tenants' => max(1, $tenants), 'capacity_agents' => max(1, $agents)])->save();
        app(AuditLogger::class)->record('scale.cell.capacity', metadata: ['cell' => $cell->id, 'tenants' => $tenants, 'agents' => $agents, 'by' => $by->id], severity: 'notice');
    }

    private static function percent(int $t, int $capT, int $a, int $capA): int
    {
        return (int) floor(100 * max($t / max(1, $capT), $a / max(1, $capA)));
    }
}
