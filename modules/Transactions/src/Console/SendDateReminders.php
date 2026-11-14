<?php

declare(strict_types=1);

namespace Modules\Transactions\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\DateReminder;
use Modules\Transactions\Deadlines\Deadlines;

/**
 * Daily: one email per deal to its agent listing key dates due in the next 2 days, due today, or missed
 * yesterday. Each date is reminded at most once a day; dates ticked off are never reminded.
 */
#[CrossTenantAccess('Send key-date reminders for every brokerage')]
final class SendDateReminders extends Command
{
    protected $signature = 'transactions:date-reminders';

    protected $description = 'Email agents about key dates coming up or missed';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->cursor() as $tenant) {
            $tenants->runAs($tenant, function () use (&$sent): void {
                $today = CarbonImmutable::today();
                $db = DB::connection(Connections::cell());
                $due = $db->table('tx_dates')->join('tx_deals', 'tx_deals.id', '=', 'tx_dates.deal_id')
                    ->whereNull('tx_dates.done_at')->whereIn('tx_deals.status', ['pre_contract', 'under_contract'])
                    ->whereBetween('tx_dates.due_on', [$today->subDay()->toDateString(), $today->addDays(app(Deadlines::class)->settings()['email_days'])->toDateString()])
                    ->where(fn ($q) => $q->whereNull('tx_dates.reminded_on')->orWhere('tx_dates.reminded_on', '<', $today->toDateString()))
                    ->orderBy('tx_dates.due_on')->get(['tx_dates.id', 'tx_dates.label', 'tx_dates.due_on', 'tx_deals.ulid', 'tx_deals.street', 'tx_deals.agent_user_id']);
                foreach ($due->groupBy('ulid') as $ulid => $dates) {
                    $first = $dates->first();
                    if ($first === null) {
                        continue;
                    }
                    $agent = User::query()->find((int) $first->agent_user_id);
                    if ($agent !== null && $agent->isActive()) {
                        /** @var list<string> $lines */
                        $lines = $dates->map(function ($d) use ($today): string {
                            $on = CarbonImmutable::parse((string) $d->due_on);
                            $days = (int) $today->diffInDays($on, false);
                            $when = match (true) {
                                $days < 0 => 'missed yesterday',
                                $days === 0 => 'today',
                                $days === 1 => 'tomorrow',
                                default => "in {$days} days",
                            };

                            return "{$d->label} — {$on->format('D j M')} ({$when})";
                        })->values()->all();
                        $agent->notify(new DateReminder((string) $first->street, $lines, url('/deals/'.$ulid)));
                        $sent++;
                    }
                    $db->table('tx_dates')->whereIn('id', $dates->pluck('id'))->update(['reminded_on' => $today->toDateString()]);
                }
            });
        }
        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
