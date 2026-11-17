<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Per-brokerage export (DS1, specs/core/12). Everything one brokerage owns, in one AES-256 protected ZIP:
 *  - every table in its cell that has a tenant_id: that brokerage's rows only, as JSON lines (one row per line);
 *  - its files: everything under the brokerage's own folders, and any file a row points to;
 *  - manifest.json: when, which tables and how many rows, which files, and the app version.
 * Three kinds:
 *  - "portable": the owner's own copy to keep or take elsewhere; passwords, sign-in secrets, API keys and voice
 *    references are left out;
 *  - "backup": for HQ to restore from; complete;
 *  - "safety": taken automatically just before a restore, so a restore can itself be undone.
 * The ZIP password is kept encrypted and shown only to the people allowed to download it. Exports expire after 7 days.
 */
final class BrokerageExport
{
    public const KINDS = ['portable', 'backup', 'safety'];

    public const KEEP_DAYS = 7;

    /** Tables never exported (framework and queue housekeeping). */
    private const SKIP = ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens', 'brokerage_exports', 'brokerage_restores'];

    /** Columns left out of a portable export. */
    private const SECRET = '/^(password|remember_token|two_factor_secret|two_factor_recovery_codes|credentials|secret|secrets|api_key|token_hash|key_hash|voice_ref|access_token|refresh_token|.*_secret|.*_token)$/';

    public function __construct(private readonly TenantContext $tenants) {}

    public function request(Tenant $tenant, string $kind, ?User $by): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Unknown export kind.');
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::control())->table('brokerage_exports')->insert(['ulid' => $ulid, 'tenant_id' => $tenant->id, 'kind' => $kind, 'status' => 'queued',
            'requested_by' => $by?->id, 'requested_by_name' => $by !== null ? mb_substr((string) $by->name, 0, 160) : 'RightAlly', 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.export.requested', metadata: ['export' => $ulid, 'tenant' => $tenant->id, 'kind' => $kind], severity: 'notice');
        Jobs\RunExport::dispatch($ulid);

        return $ulid;
    }

    /** Builds the ZIP (run by the RunExport job). */
    public function run(string $ulid): void
    {
        $db = DB::connection(Connections::control())->table('brokerage_exports');
        $row = $db->where('ulid', $ulid)->first();
        if ($row === null || $row->status !== 'queued') {
            return;
        }
        $tenant = Tenant::query()->whereKey((int) $row->tenant_id)->firstOrFail();
        DB::connection(Connections::control())->table('brokerage_exports')->where('id', $row->id)->update(['status' => 'running', 'updated_at' => now()]);
        try {
            $result = $this->tenants->runAs($tenant, fn (): array => $this->build($tenant, (string) $row->kind, $ulid));
            DB::connection(Connections::control())->table('brokerage_exports')->where('id', $row->id)->update(['status' => 'ready', 'path' => $result['path'],
                'bytes' => $result['bytes'], 'sha256' => $result['sha256'], 'password' => Crypt::encryptString($result['password']), 'tables' => json_encode($result['tables']),
                'files' => $result['files'], 'ready_at' => now(), 'expires_at' => CarbonImmutable::now()->addDays(self::KEEP_DAYS), 'updated_at' => now()]);
            app(AuditLogger::class)->record('safeguards.export.ready', metadata: ['export' => $ulid, 'tenant' => $tenant->id, 'rows' => array_sum($result['tables']),
                'files' => $result['files']], severity: 'notice');
        } catch (Throwable $e) {
            report($e);
            DB::connection(Connections::control())->table('brokerage_exports')->where('id', $row->id)->update(['status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 300), 'updated_at' => now()]);
        }
    }

    /**
     * The cell tables that hold brokerage rows.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        $schema = Schema::connection(Connections::cell());
        $out = [];
        foreach ($schema->getTables() as $t) {
            $name = (string) $t['name'];
            if (! in_array($name, self::SKIP, true) && ! in_array($name, ControlTables::ALL, true) && ! str_starts_with($name, 'sqlite_') && $schema->hasColumn($name, 'tenant_id')) {
                $out[] = $name;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return array{path: string, bytes: int, sha256: string, password: string, tables: array<string, int>, files: int}
     */
    private function build(Tenant $tenant, string $kind, string $ulid): array
    {
        $password = Str::password(32, symbols: false);
        $tmp = tempnam(sys_get_temp_dir(), 'bx').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }
        $zip->setPassword($password);
        $cell = DB::connection(Connections::cell());
        $counts = [];
        $paths = [];
        $scratch = [];
        foreach (self::tables() as $table) {
            $file = tempnam(sys_get_temp_dir(), 'bt');
            $scratch[] = $file;
            $out = fopen((string) $file, 'w');
            if ($out === false) {
                throw new RuntimeException('Could not write the export.');
            }
            $n = 0;
            $columns = Schema::connection(Connections::cell())->getColumnListing($table);
            $keep = $kind === 'portable' ? array_values(array_filter($columns, fn (string $c): bool => preg_match(self::SECRET, $c) !== 1)) : $columns;
            $order = in_array('id', $columns, true) ? 'id' : $columns[0];
            $cell->table($table)->where('tenant_id', $tenant->id)->orderBy($order)->select($keep)->chunk(1000, function ($rows) use ($out, &$n, &$paths): void {
                foreach ($rows as $r) {
                    $data = (array) $r;
                    fwrite($out, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                    $n++;
                    foreach ($data as $col => $v) {
                        if (is_string($v) && (str_ends_with((string) $col, 'path') || $col === 'path') && $v !== '' && ! str_starts_with($v, '/') && ! str_contains($v, '..')) {
                            $paths[$v] = true;
                        }
                    }
                }
            });
            fclose($out);
            if ($n > 0) {
                $zip->addFile((string) $file, 'tables/'.$table.'.jsonl');
                $zip->setEncryptionName('tables/'.$table.'.jsonl', ZipArchive::EM_AES_256);
            }
            $counts[$table] = $n;
        }
        // Files: the brokerage's own folders ("forms/12/…", "studio/{ulid}/…"), plus any file a row points to.
        $disk = Storage::disk('local');
        foreach (array_diff($disk->directories(), ['exports']) as $top) {
            foreach ([$top.'/'.$tenant->id, $top.'/'.strtolower($tenant->ulid)] as $dir) {
                if ($disk->exists($dir)) {
                    foreach ($disk->allFiles($dir) as $f) {
                        $paths[$f] = true;
                    }
                }
            }
        }
        $files = 0;
        foreach (array_keys($paths) as $p) {
            if ($disk->exists($p)) {
                $zip->addFromString('files/'.$p, (string) $disk->get($p));
                $zip->setEncryptionName('files/'.$p, ZipArchive::EM_AES_256);
                $files++;
            }
        }
        $manifest = ['format' => 'rightally-brokerage-export', 'version' => 1, 'kind' => $kind, 'export' => $ulid, 'brokerage' => ['ulid' => $tenant->ulid, 'name' => $tenant->name,
            'slug' => $tenant->slug], 'created_at' => CarbonImmutable::now()->toIso8601String(), 'tables' => $counts, 'files' => $files,
            'note' => $kind === 'portable' ? 'Passwords, sign-in secrets, API keys and voice references are not included.' : 'Complete backup for restore by RightAlly.'];
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
        $zip->close();
        foreach ($scratch as $f) {
            @unlink($f);
        }
        $path = 'exports/'.$tenant->id.'/'.$ulid.'.zip';
        $disk->put($path, (string) file_get_contents($tmp));
        $result = ['path' => $path, 'bytes' => (int) filesize($tmp), 'sha256' => (string) hash_file('sha256', $tmp), 'password' => $password, 'tables' => $counts, 'files' => $files];
        @unlink($tmp);

        return $result;
    }

    /** Removes exports past their 7 days (daily). */
    public static function expire(): int
    {
        $n = 0;
        foreach (DB::connection(Connections::control())->table('brokerage_exports')->where('status', 'ready')->where('expires_at', '<', now())->get() as $e) {
            if ($e->path !== null) {
                Storage::disk('local')->delete((string) $e->path);
            }
            DB::connection(Connections::control())->table('brokerage_exports')->where('id', $e->id)->update(['status' => 'expired', 'path' => null, 'password' => null,
                'updated_at' => now()]);
            $n++;
        }

        return $n;
    }

    /**
     * @return list<array{ulid: string, kind: string, status: string, bytes: int|null, tables: int, rows: int, files: int, by: string|null, created_at: string, expires_at: string|null}>
     */
    public static function list(int $tenantId, ?string $kind = null): array
    {
        return array_values(DB::connection(Connections::control())->table('brokerage_exports')->where('tenant_id', $tenantId)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))->orderByDesc('id')->limit(30)->get()
            ->map(function (\stdClass $e): array {
                $tables = (array) json_decode((string) ($e->tables ?? '[]'), true);

                return ['ulid' => (string) $e->ulid, 'kind' => (string) $e->kind, 'status' => (string) $e->status, 'bytes' => $e->bytes !== null ? (int) $e->bytes : null,
                    'tables' => count(array_filter($tables)), 'rows' => (int) array_sum($tables), 'files' => (int) $e->files, 'by' => $e->requested_by_name,
                    'created_at' => (string) $e->created_at, 'expires_at' => $e->expires_at !== null ? (string) $e->expires_at : null];
            })->all());
    }
}
