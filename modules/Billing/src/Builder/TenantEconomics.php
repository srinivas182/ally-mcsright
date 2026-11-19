<?php

declare(strict_types=1);

namespace Modules\Billing\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Credits\Economics;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * PB2, HQ: this month, for every brokerage and solo agent — what they pay RightAlly (package, extras and credits
 * bought) and what they cost (texts, calls and map views used, AI credits used, platform per person, hosting), so HQ
 * sees anyone who costs more than they pay. Counts and money only: no client details.
 */
final class TenantEconomics
{
    public function __construct(private readonly TenantContext $tenants, private readonly Economics $economics) {}

    /**
     * @return list<array{tenant: string, package: string|null, paid_cents: int, cost_cents: int, margin_pct: int|null, losing: bool}>
     */
    public function month(?CarbonImmutable $month = null): array
    {
        $month ??= CarbonImmutable::now()->startOfMonth();
        $period = $month->format('Y-m');
        $catalog = $this->economics->catalogCosts();
        $perCredit = $this->economics->typicalCostPerCredit();
        $hosting = $this->economics->fixedMonthlyMicrocents();
        $control = DB::connection(Connections::control());
        $out = [];
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->orderBy('name')->get() as $tenant) {
            $sub = $control->table('billing_tenant_subscriptions')->where('tenant_id', $tenant->id)->whereIn('status', ['active', 'grace'])->orderByDesc('id')->first();
            $package = $sub !== null ? $control->table('billing_packages')->where('id', $sub->package_id)->value('name') : null;
            $subscription = $sub !== null ? ($sub->interval === 'year' ? intdiv((int) $sub->price_cents, 12) : (int) $sub->price_cents) : 0;
            $extras = (int) $control->table('tenant_addons')->where('tenant_id', $tenant->id)->where('started_at', '<=', $month->endOfMonth())
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $month))->sum('price_cents');
            [$credits, $usage, $people] = $this->tenants->runAs($tenant, function () use ($period, $month, $catalog): array {
                $cell = DB::connection(Connections::cell());
                $bought = (int) $cell->table('credit_purchases')->where('status', 'paid')->whereBetween('created_at', [$month, $month->endOfMonth()])->sum('price_cents');
                $used = (int) -$cell->table('credit_ledger')->where('entry_type', 'consume')->where('period', $period)->sum('amount');
                $meters = $cell->table('usage_meters')->where('period', $period)->pluck('used', 'meter');
                $usage = (int) ($meters['texts'] ?? 0) * ($catalog['twilio.sms_segment'] ?? 0) + (int) ($meters['calls'] ?? 0) * ($catalog['twilio.call_minute'] ?? 0)
                    + (int) ($meters['maps'] ?? 0) * ($catalog['mapbox.map_load'] ?? 0);

                return [['bought' => $bought, 'used' => $used], $usage, User::query()->where('status', UserStatus::Active)->count()];
            });
            $cost = (int) ceil(($usage + $credits['used'] * $perCredit + $people * ($catalog['platform.seat'] ?? 0) + $hosting) / 1_000_000);
            $paid = $subscription + $extras + $credits['bought'];
            $out[] = ['tenant' => (string) $tenant->name, 'package' => $package !== null ? (string) $package : null, 'paid_cents' => $paid, 'cost_cents' => $cost,
                'margin_pct' => $paid > 0 ? (int) floor(($paid - $cost) * 100 / $paid) : null, 'losing' => $cost > $paid];
        }
        usort($out, fn (array $a, array $b): int => [$b['losing'], $a['margin_pct'] ?? -999] <=> [$a['losing'], $b['margin_pct'] ?? -999]);

        return $out;
    }
}
