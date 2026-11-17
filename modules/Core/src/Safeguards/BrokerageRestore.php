<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Per-brokerage restore (DS1, specs/core/12): puts one brokerage back exactly as it was in a "backup" export, without
 * touching any other brokerage in the same database. Only RightAlly HQ can do it, and only with two people:
 *  1. one HQ person asks, with a reason;
 *  2. a different HQ person approves (the asker cannot approve their own request);
 *  3. a "safety" export of the brokerage as it is now is taken first, so the restore can itself be undone;
 *  4. in one database transaction, every exported table is put back to the backup's rows for that brokerage (rows
 *     that still exist are updated in place by id, rows made since are removed, missing ones come back), and its
 *     files are written back;
 *  5. row counts are checked against the backup's manifest; on any difference everything is rolled back.
 * The audit log is never rewritten: audit rows in the backup are skipped, and the restore itself is recorded.
 */
final class BrokerageRestore
{
    /** Kept as they are: the audit trail is append-only. */
    private const NEVER = ['audit_events', 'audit_heads', 'audit_outbox', 'audit_legal_holds'];

    public function __construct(private readonly TenantContext $tenants, private readonly BrokerageExport $exports) {}

    public function ask(Tenant $tenant, string $exportUlid, string $reason, User $by): string
    {
        $export = DB::connection(Connections::control())->table('brokerage_exports')->where('ulid', $exportUlid)->where('tenant_id', $tenant->id)->first();
        if ($export === null || $export->kind !== 'backup' && $export->kind !== 'safety' || $export->status !== 'ready') {
            throw ValidationException::withMessages(['export' => 'Choose a ready backup of this brokerage.']);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Say why the brokerage is being restored (for the record).']);
        }
        if (DB::connection(Connections::control())->table('brokerage_restores')->where('tenant_id', $tenant->id)->whereIn('status', ['pending', 'approved', 'running'])->exists()) {
            throw ValidationException::withMessages(['export' => 'A restore for this brokerage is already waiting or running.']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::control())->table('brokerage_restores')->insert(['ulid' => $ulid, 'tenant_id' => $tenant->id, 'export_id' => $export->id, 'status' => 'pending',
            'reason' => mb_substr(trim($reason), 0, 300), 'requested_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.restore.requested', metadata: ['restore' => $ulid, 'tenant' => $tenant->id, 'export' => $exportUlid], severity: 'warning');

        return $ulid;
    }

    public function approve(string $ulid, User $by): void
    {
        $r = DB::connection(Connections::control())->table('brokerage_restores')->where('ulid', $ulid)->first();
        if ($r === null || $r->status !== 'pending') {
            throw ValidationException::withMessages(['restore' => 'This restore is not waiting for approval.']);
        }
        if ((int) $r->requested_by === $by->id) {
            throw ValidationException::withMessages(['restore' => 'A second person must approve a restore.']);
        }
        DB::connection(Connections::control())->table('brokerage_restores')->where('id', $r->id)->update(['status' => 'approved', 'approved_by' => $by->id,
            'approved_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.restore.approved', metadata: ['restore' => $ulid, 'tenant' => (int) $r->tenant_id], severity: 'warning');
        Jobs\RunRestore::dispatch($ulid);
    }

    public function cancel(string $ulid, User $by): void
    {
        DB::connection(Connections::control())->table('brokerage_restores')->where('ulid', $ulid)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.restore.cancelled', metadata: ['restore' => $ulid, 'by' => $by->id]);
    }

    /** Runs an approved restore (the RunRestore job). */
    public function run(string $ulid): void
    {
        $r = DB::connection(Connections::control())->table('brokerage_restores')->where('ulid', $ulid)->first();
        if ($r === null || $r->status !== 'approved') {
            return;
        }
        $tenant = Tenant::query()->whereKey((int) $r->tenant_id)->firstOrFail();
        $export = DB::connection(Connections::control())->table('brokerage_exports')->where('id', $r->export_id)->first();
        DB::connection(Connections::control())->table('brokerage_restores')->where('id', $r->id)->update(['status' => 'running', 'updated_at' => now()]);
        try {
            if ($export === null || $export->status !== 'ready' || $export->path === null) {
                throw new RuntimeException('The backup is no longer available.');
            }
            // 3. A safety copy of the brokerage as it is now, built straight away.
            $safety = $this->exports->request($tenant, 'safety', null);
            $this->exports->run($safety);
            $safetyId = DB::connection(Connections::control())->table('brokerage_exports')->where('ulid', $safety)->where('status', 'ready')->value('id');
            if ($safetyId === null) {
                throw new RuntimeException('The safety copy could not be made, so nothing was changed.');
            }
            $result = $this->tenants->runAs($tenant, fn (): array => $this->replace($tenant, (string) $export->path, Crypt::decryptString((string) $export->password)));
            DB::connection(Connections::control())->table('brokerage_restores')->where('id', $r->id)->update(['status' => 'done', 'safety_export_id' => $safetyId,
                'result' => json_encode($result), 'finished_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->record('safeguards.restore.done', metadata: ['restore' => $ulid, 'tenant' => $tenant->id, 'rows' => array_sum($result['tables']),
                'files' => $result['files']], severity: 'warning');
        } catch (Throwable $e) {
            report($e);
            DB::connection(Connections::control())->table('brokerage_restores')->where('id', $r->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300),
                'finished_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->record('safeguards.restore.failed', metadata: ['restore' => $ulid, 'tenant' => $tenant->id], severity: 'warning');
        }
    }

    /**
     * @return array{tables: array<string, int>, files: int}
     */
    private function replace(Tenant $tenant, string $path, string $password): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'br').'.zip';
        file_put_contents($tmp, (string) Storage::disk('local')->get($path));
        $zip = new ZipArchive;
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('The backup file could not be opened.');
        }
        $zip->setPassword($password);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (! is_array($manifest) || ($manifest['format'] ?? '') !== 'rightally-brokerage-export' || ($manifest['brokerage']['ulid'] ?? '') !== $tenant->ulid
            || ! in_array($manifest['kind'] ?? '', ['backup', 'safety'], true)) {
            throw new RuntimeException('This file is not a complete backup of this brokerage.');
        }
        $expected = array_map('intval', (array) ($manifest['tables'] ?? []));
        $present = BrokerageExport::tables();
        $conn = DB::connection(Connections::cell());
        $restored = [];
        $conn->transaction(function () use ($zip, $expected, $present, $conn, $tenant, &$restored): void {
            // Rows are put back by id, so a row that still exists is updated in place (nothing that points at it is
            // lost) and only rows made after the backup are removed. Links between tables are checked at the end.
            $driver = $conn->getDriverName();
            $driver === 'sqlite' ? $conn->statement('PRAGMA defer_foreign_keys = ON') : ($driver === 'mysql' || $driver === 'mariadb' ? $conn->statement('SET FOREIGN_KEY_CHECKS=0') : null);
            try {
                foreach ($present as $table) {
                    if (in_array($table, self::NEVER, true)) {
                        continue;
                    }
                    $columns = Schema::connection(Connections::cell())->getColumnListing($table);
                    $keep = array_flip($columns);
                    $rows = [];
                    $lines = $zip->getFromName('tables/'.$table.'.jsonl');
                    foreach (is_string($lines) ? (preg_split('/\R/', $lines) ?: []) : [] as $line) {
                        if ($line === '') {
                            continue;
                        }
                        $row = (array) json_decode($line, true);
                        if ((int) ($row['tenant_id'] ?? 0) !== $tenant->id) {
                            throw new RuntimeException("A row in {$table} belongs to another brokerage.");
                        }
                        $rows[] = array_intersect_key($row, $keep); // a column added since the backup keeps its default
                    }
                    if (in_array('id', $columns, true)) {
                        $ids = array_map(fn (array $r): int => (int) $r['id'], $rows);
                        $now = $conn->table($table)->where('tenant_id', $tenant->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
                        foreach (array_chunk(array_values(array_diff($now, $ids)), 500) as $gone) {
                            $conn->table($table)->where('tenant_id', $tenant->id)->whereIn('id', $gone)->delete();
                        }
                        foreach (array_chunk($rows, 200) as $batch) {
                            $update = array_values(array_diff(array_keys($batch[0]), ['id']));
                            $conn->table($table)->upsert($batch, ['id'], $update);
                        }
                    } else {
                        $conn->table($table)->where('tenant_id', $tenant->id)->delete();
                        foreach (array_chunk($rows, 200) as $batch) {
                            $conn->table($table)->insert($batch);
                        }
                    }
                    $restored[$table] = count($rows);
                }
                // 5. Check: every table has exactly the backup's rows.
                foreach ($expected as $table => $n) {
                    if (in_array($table, $present, true) && ! in_array($table, self::NEVER, true)
                        && $conn->table($table)->where('tenant_id', $tenant->id)->count() !== $n) {
                        throw new RuntimeException("Row counts do not match for {$table}; nothing was changed.");
                    }
                }
            } finally {
                if ($driver === 'mysql' || $driver === 'mariadb') {
                    $conn->statement('SET FOREIGN_KEY_CHECKS=1');
                }
            }
        });
        $files = 0;
        $disk = Storage::disk('local');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'files/') && ! str_contains($name, '..')) {
                $disk->put(substr($name, 6), (string) $zip->getFromIndex($i));
                $files++;
            }
        }
        $zip->close();
        @unlink($tmp);

        return ['tables' => $restored, 'files' => $files];
    }

    /**
     * @return list<array{ulid: string, status: string, reason: string, requested_by: string, approved_by: string|null, export: string|null, error: string|null, created_at: string, finished_at: string|null, mine: bool}>
     */
    public static function list(int $tenantId, User $viewer): array
    {
        $rows = DB::connection(Connections::control())->table('brokerage_restores')->where('tenant_id', $tenantId)->orderByDesc('id')->limit(20)->get();
        $names = User::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck('requested_by')->merge($rows->pluck('approved_by'))->filter()->unique())->get()
            ->mapWithKeys(fn (User $u): array => [$u->id => (string) $u->name]);
        $exports = DB::connection(Connections::control())->table('brokerage_exports')->whereIn('id', $rows->pluck('export_id'))->pluck('ulid', 'id');

        return array_values($rows->map(fn (\stdClass $r): array => ['ulid' => (string) $r->ulid, 'status' => (string) $r->status, 'reason' => (string) $r->reason,
            'requested_by' => (string) ($names[$r->requested_by] ?? ''), 'approved_by' => $r->approved_by !== null ? (string) ($names[$r->approved_by] ?? '') : null,
            'export' => isset($exports[$r->export_id]) ? (string) $exports[$r->export_id] : null, 'error' => $r->error !== null ? (string) $r->error : null,
            'created_at' => (string) $r->created_at, 'finished_at' => $r->finished_at !== null ? (string) $r->finished_at : null,
            'mine' => (int) $r->requested_by === $viewer->id])->all());
    }
}
