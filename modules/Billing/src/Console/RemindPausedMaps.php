<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Usage\MapsPaused;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Daily: tells owners of brokerages with a paused website map how many visitors tried to use it (specs/billing/05). */
#[CrossTenantAccess('Reminds owners of every brokerage whose website map is paused for lack of credits')]
final class RemindPausedMaps extends Command
{
    protected $signature = 'credits:paused-maps';

    protected $description = 'Remind owners whose website map is paused how many visitors tried to use it';

    public function handle(TenantContext $tenants): int
    {
        $paused = DB::connection(Connections::cell())->table('usage_meters')->where('period', CreditLedger::period())->where('meter', 'maps')
            ->whereNotNull('paused_at')->where('missed', '>', 0)->get(['tenant_id', 'paused_at', 'missed']);
        foreach ($paused as $row) {
            $tenant = Tenant::query()->whereKey((int) $row->tenant_id)->first();
            if ($tenant === null) {
                continue;
            }
            $tenants->runAs($tenant, function () use ($row): void {
                $owners = User::query()->where('kind', UserKind::Owner)->get()->filter(fn (User $u): bool => $u->isActive());
                Notification::send($owners, new MapsPaused((int) $row->missed, (string) $row->paused_at, true));
            });
        }
        $this->info($paused->count().' paused map(s).');

        return self::SUCCESS;
    }
}
