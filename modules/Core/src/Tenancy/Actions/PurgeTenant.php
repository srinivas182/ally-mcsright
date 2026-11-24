<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Safeguards\BrokerageExport;
use Modules\Core\Safeguards\ControlTables;
use Modules\Core\Safeguards\Structure;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;

/**
 * TD1: deletes a closed brokerage for good: every row it owns (in its own database and in the shared control tables),
 * its files, its website and its addresses, so the address can be used again. Only a brokerage that has been closed
 * (closing or closed) can be deleted, never HQ. HQ does it by hand ("Delete permanently", typing the address and their
 * password); it never happens on its own, even once a closed brokerage's keep period ends (HQ is told it is due).
 *
 * The audit trail is kept (it has its own retention); the tenant record stays as a deleted marker so the audit and
 * billing history still say which brokerage they were about.
 */
final class PurgeTenant
{
    /** Tables never emptied here: the sealed audit trail and the framework's own. */
    private const KEEP = ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens', 'tenants'];

    public function __construct(private readonly TenantContext $tenants, private readonly DomainResolver $resolver) {}

    /** @return array{rows: int, files: int} what was deleted */
    public function handle(Tenant $tenant, ?int $byUserId = null): array
    {
        if ($tenant->isProtected()) {
            throw ValidationException::withMessages(['tenant' => $tenant->isHq() ? 'HQ cannot be deleted.' : 'The design demo website is run by RightAlly and cannot be deleted.']);
        }
        if (! in_array($tenant->status, [TenantStatus::Closing, TenantStatus::Closed], true)) {
            throw ValidationException::withMessages(['tenant' => 'Close this brokerage first. Only a closed brokerage can be deleted.']);
        }
        $id = $tenant->id;
        $slug = $tenant->slug;
        $hosts = TenantDomain::query()->where('tenant_id', $id)->pluck('host')->all();

        // 1. Its own data, in its own cell (or dedicated database).
        // Its people's sign-in keys, app tokens and sessions are kept by person, not by brokerage.
        $people = $this->tenants->runAs($tenant, fn (): array => DB::connection(Connections::cell())->table('users')->where('tenant_id', $id)->pluck('id')->map(fn ($v): int => (int) $v)->all());

        // Rows are removed table by table, so links between its own rows are not checked while it happens.
        $own = BrokerageExport::tables();
        $rows = $this->tenants->runAs($tenant, function () use ($id, $own): int {
            $cell = DB::connection(Connections::cell());
            $n = 0;
            $cell->getSchemaBuilder()->withoutForeignKeyConstraints(function () use ($cell, $own, $id, &$n): void {
                foreach ($own as $table) {
                    if (! $this->keep($table)) {
                        $n += $cell->table($table)->where('tenant_id', $id)->delete();
                    }
                }
            });

            return $n;
        });

        // 2. Its rows in the shared control tables (addresses, boards, billing settings, sign-in directory …).
        $control = DB::connection(Connections::control());
        $schema = Schema::connection(Connections::control());
        $schema->withoutForeignKeyConstraints(function () use ($control, $schema, $own, $id, &$rows): void {
            foreach (Structure::tableNames($control) as $table) {
                if ($this->keep($table) || str_starts_with($table, 'sqlite_') || ! $schema->hasColumn($table, 'tenant_id')) {
                    continue;
                }
                if (in_array($table, ControlTables::ALL, true) || ! in_array($table, $own, true)) {
                    $rows += $control->table($table)->where('tenant_id', $id)->delete();
                }
            }
        });

        $default = DB::connection();
        foreach (['passkeys', 'oauth_access_tokens', 'oauth_auth_codes', 'sessions'] as $table) {
            if ($people !== [] && $default->getSchemaBuilder()->hasTable($table) && $default->getSchemaBuilder()->hasColumn($table, 'user_id')) {
                foreach (array_chunk($people, 500) as $chunk) {
                    $rows += $default->table($table)->whereIn('user_id', $chunk)->delete();
                }
            }
        }

        // 3. Its files.
        $files = 0;
        foreach (['local', 'public'] as $diskName) {
            $disk = Storage::disk($diskName);
            foreach ($disk->directories() as $top) {
                foreach ([$top.'/'.$id, $top.'/'.strtolower((string) $tenant->ulid)] as $dir) {
                    if ($disk->exists($dir)) {
                        $files += count($disk->allFiles($dir));
                        $disk->deleteDirectory($dir);
                    }
                }
            }
        }

        // 4. The tenant stays as a deleted marker; its address is free again.
        $tenant->forceFill(['slug' => 'deleted-'.$id, 'name' => 'Deleted: '.$tenant->name, 'status' => TenantStatus::Closed, 'purge_after' => null])->save();
        $tenant->delete();
        foreach ($hosts as $host) {
            $this->resolver->forget((string) $host);
        }
        app(AuditLogger::class)->record('core.tenant.purged', metadata: ['tenant' => $id, 'slug' => $slug, 'rows' => $rows, 'files' => $files, 'by' => $byUserId], severity: 'critical');

        return ['rows' => $rows, 'files' => $files];
    }

    private function keep(string $table): bool
    {
        return in_array($table, self::KEEP, true) || str_starts_with($table, 'audit_');
    }
}
