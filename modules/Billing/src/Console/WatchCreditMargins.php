<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\Economics;
use Modules\Billing\Credits\MarginAlert;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Usage\PlatformMapViews;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;

/** Daily: emails HQ when any credit price no longer covers its real cost at the minimum margin (specs/billing/02). */
#[CrossTenantAccess('Reads AI cost across brokerages and emails RightAlly HQ staff')]
final class WatchCreditMargins extends Command
{
    protected $signature = 'credits:margin-watch';

    protected $description = 'Email HQ when credit prices fall below the minimum margin';

    public function handle(Economics $economics, RateCard $rates): int
    {
        $this->watchMapViews($rates);
        $alerts = $economics->marginAlerts();
        if ($alerts === []) {
            $this->info('Every credit price is above the minimum margin.');

            return self::SUCCESS;
        }
        $hq = CrossTenant::query(User::class, $this)->where('kind', UserKind::HqStaff)->where('status', 'active')->get();
        if ($hq->isNotEmpty()) {
            Notification::send($hq, new MarginAlert($alerts, $rates->margins()['floor']));
        }
        $this->warn(count($alerts).' action(s) below the minimum margin.');

        return self::SUCCESS;
    }

    /** Once a month: tells HQ when all websites together pass HQ's map-view alert line. Nothing pauses. */
    private function watchMapViews(RateCard $rates): void
    {
        $period = CreditLedger::period();
        $total = (int) DB::connection(Connections::cell())->table('usage_meters')->where('period', $period)->where('meter', 'maps')->sum('used');
        $control = DB::connection(Connections::control())->table('credit_settings');
        if ($total < $rates->mapsAlertViews() || (clone $control)->value('maps_alert_sent') === $period) {
            return;
        }
        (clone $control)->exists() ? (clone $control)->update(['maps_alert_sent' => $period]) : (clone $control)->insert(['maps_alert_sent' => $period, 'created_at' => now(), 'updated_at' => now()]);
        $hq = CrossTenant::query(User::class, $this)->where('kind', UserKind::HqStaff)->where('status', 'active')->get();
        Notification::send($hq, new PlatformMapViews($total, $rates->mapsAlertViews()));
        $this->warn("Map views this month: {$total}.");
    }
}
