<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Audit\AuditRetention;
use Modules\Core\Audit\SecurityDigest;
use Modules\Core\Audit\WeeklySecurityDigestMail;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * audit:retention (daily) and audit:weekly-digest (Mondays) for every active brokerage.
 */
final class AuditMaintenance extends Command
{
    protected $signature = 'audit:maintain {task : retention or weekly-digest}';

    protected $description = 'Audit retention and the weekly security digest';

    public function handle(TenantContext $tenants): int
    {
        $task = (string) $this->argument('task');
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $tenants->runAs($tenant, function () use ($task, $tenant): void {
                    if ($task === 'retention') {
                        $removed = app(AuditRetention::class)->purge();
                        $this->line("{$tenant->slug}: {$removed} removed");

                        return;
                    }
                    $digest = app(SecurityDigest::class)->build(CarbonImmutable::now()->subWeek());
                    foreach (User::query()->where('kind', UserKind::Owner)->where('status', 'active')->get() as $owner) {
                        $owner->notify(new WeeklySecurityDigestMail($tenant->name, $digest));
                    }
                });
            } catch (Throwable $e) {
                $this->warn("{$tenant->slug}: ".class_basename($e));
            }
        }

        return self::SUCCESS;
    }
}
