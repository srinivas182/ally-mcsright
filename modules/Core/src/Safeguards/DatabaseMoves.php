<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\CellRouting;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;
use Throwable;

/**
 * Moving a brokerage to or from a dedicated database (DS2, specs/core/12, ADR 0012). HQ only, two people:
 *  1. target: a dedicated database (connection details, encrypted) or a shared cell; tested (reachable, supported
 *     version, empty for this brokerage);
 *  2. plan: rows per table, estimated time and read-only window;
 *  3. a second HQ person approves;
 *  4. structure → copy while the brokerage keeps working → final sync in a short read-only window → verify row
 *     counts and money totals per table → switch the brokerage's cell in the control plane;
 *  5. the old copy is kept 14 days with one-click switch-back, then removed by the daily clean-up.
 * The owner is told before and after. A "drill" runs steps 4's copy and checks into the target and then removes the
 * copy, without switching: proof a move would work.
 */
final class DatabaseMoves
{
    public const KEEP_DAYS = 14;

    public const ROWS_PER_SECOND = 5000;

    /** The audit trail is never deleted: it is copied by adding what the target does not have yet. */
    public const APPEND_ONLY = ['audit_events', 'audit_heads', 'audit_outbox', 'audit_legal_holds'];

    public function __construct(private readonly TenantContext $tenants, private readonly CellRouting $routing) {}

    /** @return array{tables: array<string, int>, rows: int, minutes: int, read_only_minutes: int} */
    public function plan(Tenant $tenant): array
    {
        $tables = $this->tenants->runAs($tenant, function () use ($tenant): array {
            $out = [];
            foreach (BrokerageExport::tables() as $t) {
                $out[$t] = DB::connection(Connections::cell())->table($t)->where('tenant_id', $tenant->id)->count();
            }

            return $out;
        });
        $rows = array_sum($tables);

        return ['tables' => $tables, 'rows' => $rows, 'minutes' => max(1, (int) ceil($rows / self::ROWS_PER_SECOND / 60 * 2)),
            'read_only_minutes' => max(1, (int) ceil($rows / self::ROWS_PER_SECOND / 60))];
    }

    public function ask(Tenant $tenant, Cell $to, string $kind, User $by): string
    {
        if (! in_array($kind, ['move', 'drill'], true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown move.']);
        }
        $from = (int) $tenant->getAttribute('cell_id');
        if ($to->id === $from) {
            throw ValidationException::withMessages(['cell' => 'The brokerage is already there.']);
        }
        if (DB::connection(Connections::control())->table('brokerage_moves')->where('tenant_id', $tenant->id)
            ->whereIn('status', ['planned', 'approved', 'copying', 'final_sync', 'verifying'])->exists()) {
            throw ValidationException::withMessages(['cell' => 'A move for this brokerage is already planned or running.']);
        }
        $test = app(DedicatedCells::class)->test($to, $tenant);
        if (! $test['ok']) {
            throw ValidationException::withMessages(['cell' => 'The target did not pass the checks: '.implode('; ', array_map(fn (array $c): string => $c['label'].' ('.$c['detail'].')',
                array_filter($test['checks'], fn (array $c): bool => ! $c['ok']))).'.']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::control())->table('brokerage_moves')->insert(['ulid' => $ulid, 'tenant_id' => $tenant->id, 'from_cell_id' => $from, 'to_cell_id' => $to->id,
            'kind' => $kind, 'status' => 'planned', 'plan' => json_encode($this->plan($tenant) + ['checks' => $test['checks']]), 'requested_by' => $by->id,
            'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.move.planned', metadata: ['move' => $ulid, 'tenant' => $tenant->id, 'to' => $to->id, 'kind' => $kind], severity: 'warning');

        return $ulid;
    }

    public function approve(string $ulid, User $by): void
    {
        $m = DB::connection(Connections::control())->table('brokerage_moves')->where('ulid', $ulid)->first();
        if ($m === null || $m->status !== 'planned') {
            throw ValidationException::withMessages(['move' => 'This move is not waiting for approval.']);
        }
        if ((int) $m->requested_by === $by->id) {
            throw ValidationException::withMessages(['move' => 'A second person must approve a move.']);
        }
        DB::connection(Connections::control())->table('brokerage_moves')->where('id', $m->id)->update(['status' => 'approved', 'approved_by' => $by->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.move.approved', metadata: ['move' => $ulid], severity: 'warning');
        if ($m->kind === 'move') {
            $this->tellOwners((int) $m->tenant_id, 'before', (array) json_decode((string) $m->plan, true));
        }
        Jobs\RunMove::dispatch($ulid);
    }

    public function cancel(string $ulid, User $by): void
    {
        DB::connection(Connections::control())->table('brokerage_moves')->where('ulid', $ulid)->where('status', 'planned')->update(['status' => 'cancelled', 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.move.cancelled', metadata: ['move' => $ulid, 'by' => $by->id]);
    }

    /** Runs an approved move or drill (the RunMove job). */
    public function run(string $ulid): void
    {
        $m = DB::connection(Connections::control())->table('brokerage_moves')->where('ulid', $ulid)->first();
        if ($m === null || $m->status !== 'approved') {
            return;
        }
        $tenant = Tenant::query()->whereKey((int) $m->tenant_id)->firstOrFail();
        $fromCell = Cell::query()->whereKey((int) $m->from_cell_id)->first();
        $toCell = Cell::query()->whereKey((int) $m->to_cell_id)->firstOrFail();
        $step = fn (string $status, string $text, int $pct) => DB::connection(Connections::control())->table('brokerage_moves')->where('id', $m->id)
            ->update(['status' => $status, 'step' => $text, 'progress' => $pct, 'updated_at' => now()]);
        try {
            $source = DB::connection($this->routing->connectionFor($tenant));
            $target = DB::connection(self::name($toCell));
            $tables = $this->tenants->runAs($tenant, fn (): array => BrokerageExport::tables());
            $step('copying', 'Making the tables', 10);
            Structure::sync($source, $target, $tables);
            if ((bool) $toCell->getAttribute('dedicated')) {
                Structure::offsetIds($target, $tables, $toCell->id * 1_000_000_000_000);
            }
            $step('copying', 'Copying while the brokerage keeps working', 30);
            $this->copy($source, $target, $tables, $tenant->id);
            if ($m->kind === 'move') {
                $step('final_sync', 'Final copy (brief read-only window)', 60);
                Cache::put(self::readOnlyKey($tenant->id), true, now()->addHours(2));
                $this->copy($source, $target, $tables, $tenant->id);
            }
            $step('verifying', 'Checking every table', 80);
            $check = self::verify($source, $target, $tables, $tenant->id);
            if ($check['problems'] !== []) {
                throw new RuntimeException('Check failed: '.implode('; ', array_slice($check['problems'], 0, 3)));
            }
            if ($m->kind === 'drill') {
                $this->wipe($target, $tables, $tenant->id);
                DB::connection(Connections::control())->table('brokerage_moves')->where('id', $m->id)->update(['status' => 'done', 'step' => 'Drill passed; the copy was removed',
                    'progress' => 100, 'result' => json_encode($check), 'updated_at' => now()]);
                app(AuditLogger::class)->record('safeguards.move.drill_passed', metadata: ['move' => $ulid, 'rows' => $check['rows']], severity: 'notice');

                return;
            }
            $this->switchTo($tenant, $toCell);
            Cache::forget(self::readOnlyKey($tenant->id));
            DB::connection(Connections::control())->table('brokerage_moves')->where('id', $m->id)->update(['status' => 'switched', 'step' => 'Moved',
                'progress' => 100, 'result' => json_encode($check), 'switched_at' => now(), 'keep_until' => now()->addDays(self::KEEP_DAYS), 'updated_at' => now()]);
            app(AuditLogger::class)->record('safeguards.move.switched', metadata: ['move' => $ulid, 'tenant' => $tenant->id, 'from' => $fromCell?->id, 'to' => $toCell->id,
                'rows' => $check['rows']], severity: 'warning');
            $this->tellOwners($tenant->id, 'after', []);
        } catch (Throwable $e) {
            report($e);
            Cache::forget(self::readOnlyKey($tenant->id));
            DB::connection(Connections::control())->table('brokerage_moves')->where('id', $m->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300),
                'updated_at' => now()]);
            app(AuditLogger::class)->record('safeguards.move.failed', metadata: ['move' => $ulid, 'tenant' => $tenant->id], severity: 'warning');
        }
    }

    /**
     * One click within 14 days: copies the brokerage's current data back to where it was (replacing the old copy,
     * which may be out of date) and switches back; a move like any other, without a second approval because it
     * undoes an approved move.
     */
    public function switchBack(string $ulid, User $by): void
    {
        $m = DB::connection(Connections::control())->table('brokerage_moves')->where('ulid', $ulid)->first();
        if ($m === null || $m->status !== 'switched' || $m->keep_until === null || CarbonImmutable::parse((string) $m->keep_until)->isPast()) {
            throw ValidationException::withMessages(['move' => 'Switch-back is no longer available for this move.']);
        }
        $back = (string) Str::ulid();
        DB::connection(Connections::control())->table('brokerage_moves')->insert(['ulid' => $back, 'tenant_id' => $m->tenant_id, 'from_cell_id' => $m->to_cell_id,
            'to_cell_id' => $m->from_cell_id, 'kind' => 'move', 'status' => 'approved', 'requested_by' => $by->id, 'approved_by' => $by->id,
            'plan' => json_encode(['switch_back_of' => $ulid]), 'created_at' => now(), 'updated_at' => now()]);
        DB::connection(Connections::control())->table('brokerage_moves')->where('id', $m->id)->update(['status' => 'done', 'step' => 'Switched back', 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.move.switch_back', metadata: ['move' => $ulid, 'back' => $back], severity: 'warning');
        Jobs\RunMove::dispatch($back);
    }

    /** Daily: removes the old copy once the 14 days are over. */
    public function cleanUp(): int
    {
        $n = 0;
        foreach (DB::connection(Connections::control())->table('brokerage_moves')->where('status', 'switched')->whereNull('cleaned_at')->where('keep_until', '<', now())->get() as $m) {
            $tenant = Tenant::query()->whereKey((int) $m->tenant_id)->first();
            $old = Cell::query()->whereKey((int) $m->from_cell_id)->first();
            if ($tenant !== null && $old !== null && (int) $tenant->getAttribute('cell_id') !== $old->id) {
                $this->wipe(DB::connection(self::name($old)), $this->tenants->runAs($tenant, fn (): array => BrokerageExport::tables()), $tenant->id);
            }
            DB::connection(Connections::control())->table('brokerage_moves')->where('id', $m->id)->update(['status' => 'done', 'cleaned_at' => now(), 'updated_at' => now()]);
            $n++;
        }

        return $n;
    }

    public static function readOnlyKey(int $tenantId): string
    {
        return 'safeguards:moving:'.$tenantId;
    }

    /**
     * Row counts and money totals (every *_cents column) per table, source against target.
     *
     * @param  list<string>  $tables
     * @return array{rows: int, problems: list<string>}
     */
    public static function verify(Connection $source, Connection $target, array $tables, int $tenantId): array
    {
        $problems = [];
        $rows = 0;
        foreach ($tables as $t) {
            $a = $source->table($t)->where('tenant_id', $tenantId)->count();
            $b = $target->table($t)->where('tenant_id', $tenantId)->count();
            $rows += $a;
            if ($a !== $b) {
                $problems[] = "{$t}: {$a} rows here, {$b} there";

                continue;
            }
            foreach ($source->getSchemaBuilder()->getColumnListing($t) as $col) {
                if (str_ends_with($col, '_cents')) {
                    $x = (string) $source->table($t)->where('tenant_id', $tenantId)->sum($col);
                    $y = (string) $target->table($t)->where('tenant_id', $tenantId)->sum($col);
                    if ((int) $x !== (int) $y) {
                        $problems[] = "{$t}.{$col}: totals differ";
                    }
                }
            }
        }

        return ['rows' => $rows, 'problems' => $problems];
    }

    public static function name(Cell $cell): string
    {
        $name = DedicatedCells::register($cell);
        if ($name !== null) {
            return $name;
        }

        return is_array(config('database.connections.'.$cell->db_connection)) ? $cell->db_connection : (string) (Connections::cell() ?? config('database.default'));
    }

    /** @param list<string> $tables */
    private function copy(Connection $source, Connection $target, array $tables, int $tenantId): void
    {
        $this->wipe($target, $tables, $tenantId);
        // Tables are copied one after another, so links between them are checked once everything is there (verify).
        self::relaxLinks($target);
        try {
            foreach ($tables as $t) {
                $columns = $source->getSchemaBuilder()->getColumnListing($t);
                $keep = array_flip($target->getSchemaBuilder()->getColumnListing($t));
                $order = in_array('id', $columns, true) ? 'id' : $columns[0];
                $append = in_array($t, self::APPEND_ONLY, true);
                $source->table($t)->where('tenant_id', $tenantId)->orderBy($order)->chunk(500, function ($rows) use ($target, $t, $keep, $append): void {
                    $batch = array_map(fn ($r): array => array_intersect_key((array) $r, $keep), $rows->all());
                    $append ? $target->table($t)->insertOrIgnore($batch) : $target->table($t)->insert($batch);
                });
            }
        } finally {
            $target->getSchemaBuilder()->enableForeignKeyConstraints();
        }
    }

    /** @param list<string> $tables */
    private function wipe(Connection $conn, array $tables, int $tenantId): void
    {
        $have = array_map(fn (array $t): string => (string) $t['name'], $conn->getSchemaBuilder()->getTables());
        self::relaxLinks($conn);
        try {
            foreach ($tables as $t) {
                if (in_array($t, $have, true) && ! in_array($t, self::APPEND_ONLY, true)) {
                    $conn->table($t)->where('tenant_id', $tenantId)->delete();
                }
            }
        } finally {
            $conn->getSchemaBuilder()->enableForeignKeyConstraints();
        }
    }

    /** Links between tables are checked at the end, not row by row (SQLite inside a transaction can only defer them). */
    private static function relaxLinks(Connection $conn): void
    {
        $conn->getSchemaBuilder()->disableForeignKeyConstraints();
        if ($conn->getDriverName() === 'sqlite' && $conn->transactionLevel() > 0) {
            $conn->statement('PRAGMA defer_foreign_keys = ON');
        }
    }

    private function switchTo(Tenant $tenant, Cell $cell): void
    {
        $tenant->forceFill(['cell_id' => $cell->id])->save();
        $this->routing->flush();
        if ($this->tenants->idOrNull() === $tenant->id) {
            $this->tenants->set($tenant->fresh() ?? $tenant);
        }
    }

    /** @param array<string, mixed> $plan */
    private function tellOwners(int $tenantId, string $when, array $plan): void
    {
        $tenant = Tenant::query()->whereKey($tenantId)->first();
        if ($tenant === null) {
            return;
        }
        $this->tenants->runAs($tenant, function () use ($when, $plan): void {
            foreach (User::query()->where('kind', UserKind::Owner->value)->get() as $owner) {
                $owner->notify(new MoveNotice($when, (int) ($plan['read_only_minutes'] ?? 1)));
            }
        });
    }
}
