<?php

declare(strict_types=1);

namespace Modules\Marketing\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Marketing\Content\IndexStats;
use Modules\Marketing\Content\TenantDesk;
use Modules\Marketing\Content\Writer;
use Modules\Sites\Ai\ContentStudio;

/**
 * MK4, every night after HQ's desk: for each brokerage (and agent) blog writer that is on and still paid for, fetch its
 * Search Console numbers when connected, then write the day's drafts for the owner to approve. Levels move in
 * content:desk (Cadence::review covers every desk).
 */
final class TenantDesks extends Command
{
    protected $signature = 'content:tenant-desks';

    protected $description = 'Brokerage and agent AI blog writers: the day\'s drafts for approval';

    public function handle(TenantContext $tenants, IndexStats $stats): int
    {
        $written = 0;
        $byTenant = DB::connection(Connections::control())->table('content_desks')->where('tenant_id', '>', 0)->where('mode', '!=', 'off')->get()->groupBy('tenant_id');
        foreach ($byTenant as $tenantId => $desks) {
            $tenant = Tenant::query()->whereKey((int) $tenantId)->where('status', 'active')->first();
            if ($tenant === null) {
                continue;
            }
            try {
                $written += $tenants->runAs($tenant, function () use ($desks, $stats): int {
                    $n = 0;
                    $owner = User::query()->where('kind', UserKind::Owner)->orderBy('id')->first();
                    foreach ($desks as $desk) {
                        $who = $desk->user_id !== null ? User::query()->find((int) $desk->user_id) : $owner;
                        if ($who === null || ! TenantDesk::included($who)) {
                            continue; // the add-on stopped, or the agent left: nothing is written or charged
                        }
                        try {
                            $stats->refresh($desk);
                        } catch (\Throwable $e) {
                            report($e);
                        }
                        $n += app(TenantDesk::class)->writeFor($desk, app(Writer::class), app(ContentStudio::class));
                    }

                    return $n;
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info($written.' draft(s) ready for approval.');

        return self::SUCCESS;
    }
}
