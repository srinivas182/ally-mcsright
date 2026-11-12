<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\AgentSubscription;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Partner earnings (specs/core/07): for every agent invoice actually paid, the brokerage earns its
 * commission on the floor price plus 100% of its markup; refunds reverse the matching part on the
 * next statement; sales tax never counts. Monthly statements, then payouts by Stripe Connect when the
 * amount reaches the minimum (smaller or negative amounts carry forward).
 */
final class PartnerEarnings
{
    public const MINIMUM_PAYOUT_CENTS = 2_500;

    public function __construct(private readonly PaymentGateway $gateway, private readonly Catalogue $catalogue, private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function recordCollection(AgentSubscription $sub, array $invoice): void
    {
        $ref = (string) ($invoice['id'] ?? '');
        $plan = AgentPlan::query()->find($sub->agent_plan_id);
        if ($ref === '' || $plan === null) {
            return;
        }
        $collected = max(0, (int) ($invoice['amount_paid'] ?? 0) - (int) ($invoice['tax'] ?? 0));
        DB::connection(Connections::control())->table('billing_collections')->insertOrIgnore([
            'tenant_id' => $sub->tenant_id, 'user_id' => $sub->user_id, 'agent_plan_id' => $plan->id, 'invoice_ref' => $ref,
            'amount_cents' => $collected, 'price_cents' => $sub->price_cents, 'floor_cents' => $this->catalogue->priceInForce($plan, 'floor'),
            'month' => CarbonImmutable::now()->format('Y-m'), 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A refund (cumulative amount) on a paid agent invoice. */
    public function recordRefund(string $invoiceRef, int $refundedCents): bool
    {
        return DB::connection(Connections::control())->table('billing_collections')->where('invoice_ref', $invoiceRef)
            ->update(['refunded_cents' => max(0, $refundedCents), 'updated_at' => now()]) > 0;
    }

    /**
     * The statement for a month (generated once; never changed afterwards).
     *
     * @return array<string, mixed>
     */
    public function statement(Tenant $tenant, string $month): array
    {
        $db = DB::connection(Connections::control());
        $existing = $db->table('billing_partner_statements')->where('tenant_id', $tenant->id)->where('month', $month)->first();
        if ($existing !== null) {
            return (array) $existing;
        }

        $rows = $db->table('billing_collections')->where('tenant_id', $tenant->id)->where('month', $month)->get();
        $paying = $rows->pluck('user_id')->unique()->count();
        $percent = $this->catalogue->commissionPercent($paying);
        $names = $this->tenants->runAs($tenant, fn () => User::query()->whereIn('id', $rows->pluck('user_id'))->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name])->all());

        $commission = 0;
        $markup = 0;
        $lines = [];
        foreach ($rows as $r) {
            $c = (int) floor((int) $r->floor_cents * (float) $percent / 100);
            $m = max(0, (int) $r->price_cents - (int) $r->floor_cents);
            $commission += $c;
            $markup += $m;
            $lines[] = ['agent' => $names[$r->user_id] ?? 'Former agent', 'plan' => AgentPlan::query()->whereKey($r->agent_plan_id)->value('name'),
                'price_cents' => (int) $r->price_cents, 'floor_cents' => (int) $r->floor_cents, 'commission_cents' => $c, 'markup_cents' => $m];
        }

        // Refunds not yet reflected: reverse the matching share of what was earned on those invoices.
        $adjustments = 0;
        foreach ($db->table('billing_collections')->where('tenant_id', $tenant->id)->whereColumn('refunded_cents', '>', 'refund_settled_cents')->get() as $r) {
            $earned = (int) floor((int) $r->floor_cents * (float) $this->percentFor($tenant, (string) $r->month) / 100) + max(0, (int) $r->price_cents - (int) $r->floor_cents);
            $share = (int) $r->amount_cents > 0 ? min(1, ((int) $r->refunded_cents - (int) $r->refund_settled_cents) / (int) $r->amount_cents) : 1;
            $reverse = (int) round($earned * $share);
            $adjustments -= $reverse;
            $lines[] = ['agent' => $names[$r->user_id] ?? 'Refund', 'plan' => 'Refund on '.$r->month, 'adjustment_cents' => -$reverse];
            $db->table('billing_collections')->where('id', $r->id)->update(['refund_settled_cents' => (int) $r->refunded_cents]);
        }

        $carried = (int) $db->table('billing_partner_statements')->where('tenant_id', $tenant->id)->where('status', 'carried')->where('month', '<', $month)->sum('payable_cents');
        $db->table('billing_partner_statements')->where('tenant_id', $tenant->id)->where('status', 'carried')->where('month', '<', $month)->update(['status' => 'rolled', 'updated_at' => now()]);

        $row = [
            'ulid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'month' => $month, 'paying_agents' => $paying, 'percent' => $percent,
            'commission_cents' => $commission, 'markup_cents' => $markup, 'adjustments_cents' => $adjustments, 'carried_in_cents' => $carried,
            'payable_cents' => $commission + $markup + $adjustments + $carried, 'status' => 'open', 'lines' => json_encode($lines), 'created_at' => now(), 'updated_at' => now(),
        ];
        $db->table('billing_partner_statements')->insert($row);
        app(AuditLogger::class)->record('billing.statement.created', metadata: ['month' => $month, 'payable_cents' => $row['payable_cents']], severity: 'notice', tenantId: $tenant->id);

        return $row;
    }

    /**
     * Pays an open statement when it reaches the minimum and the brokerage can receive payouts;
     * otherwise carries it into next month. Safe to run again (idempotent transfer key).
     */
    public function payout(Tenant $tenant, string $month): string
    {
        $db = DB::connection(Connections::control());
        $s = $db->table('billing_partner_statements')->where('tenant_id', $tenant->id)->where('month', $month)->where('status', 'open')->first();
        if ($s === null) {
            return 'none';
        }
        $account = $tenant->getAttribute('connect_account');
        if ((int) $s->payable_cents < self::MINIMUM_PAYOUT_CENTS || ! $tenant->getAttribute('connect_ready') || ! is_string($account)) {
            $db->table('billing_partner_statements')->where('id', $s->id)->update(['status' => 'carried', 'updated_at' => now()]);

            return 'carried';
        }
        $ref = $this->gateway->transfer($account, (int) $s->payable_cents, "RightAlly partner earnings {$month}", 'payout:'.$s->ulid);
        $db->table('billing_partner_statements')->where('id', $s->id)->update(['status' => 'paid', 'payout_ref' => $ref, 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.payout.sent', metadata: ['month' => $month, 'cents' => (int) $s->payable_cents, 'transfer' => $ref], severity: 'notice', tenantId: $tenant->id);

        return 'paid';
    }

    /** Owner: set up (or continue) Stripe Connect to receive payouts. */
    public function onboarding(User $owner, string $returnBase): string
    {
        if (! $owner->isKind(UserKind::Owner)) {
            throw ValidationException::withMessages(['connect' => 'Owners set up payouts.']);
        }
        $tenant = $this->tenants->current();
        $account = $tenant->getAttribute('connect_account');
        if (! is_string($account) || $account === '') {
            $account = $this->gateway->createConnectAccount($owner->email, (string) $tenant->name);
            Tenant::query()->whereKey($tenant->id)->update(['connect_account' => $account]);
            $this->tenants->set($tenant->fresh() ?? $tenant);
            app(AuditLogger::class)->record('billing.connect.started', metadata: ['account' => $account], severity: 'notice');
        }

        return $this->gateway->connectOnboarding($account, $returnBase.'/settings/partner?connect=retry', $returnBase.'/settings/partner?connect=done');
    }

    /**
     * @param  array<string, mixed>  $account
     */
    public function accountUpdated(array $account): void
    {
        $ready = (bool) ($account['payouts_enabled'] ?? false) && (bool) ($account['details_submitted'] ?? false);
        Tenant::query()->where('connect_account', (string) ($account['id'] ?? ''))->update(['connect_ready' => $ready]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $tenant = $this->tenants->current();
        $month = CarbonImmutable::now()->format('Y-m');
        $db = DB::connection(Connections::control());
        $byPlan = AgentSubscription::query()->whereIn('status', ['active', 'grace'])->where('sponsored', false)->get()->groupBy('agent_plan_id')
            ->map(fn ($subs, $planId): array => ['plan' => AgentPlan::query()->whereKey((int) $planId)->value('name'), 'agents' => $subs->count(), 'monthly_cents' => (int) $subs->sum('price_cents')])->values();
        $sofar = $db->table('billing_collections')->where('tenant_id', $tenant->id)->where('month', $month)->get();
        $percent = $this->catalogue->commissionPercent($sofar->pluck('user_id')->unique()->count());

        return [
            'connect' => ['account' => $tenant->getAttribute('connect_account') !== null, 'ready' => (bool) $tenant->getAttribute('connect_ready')],
            'byPlan' => $byPlan,
            'thisMonth' => [
                'collected_cents' => (int) $sofar->sum('amount_cents'),
                'commission_cents' => (int) $sofar->sum(fn ($r): int => (int) floor((int) $r->floor_cents * (float) $percent / 100)),
                'markup_cents' => (int) $sofar->sum(fn ($r): int => max(0, (int) $r->price_cents - (int) $r->floor_cents)),
                'percent' => $percent,
            ],
            'statements' => $db->table('billing_partner_statements')->where('tenant_id', $tenant->id)->orderByDesc('month')->limit(24)->get()
                ->map(fn ($s): array => ['ulid' => $s->ulid, 'month' => $s->month, 'paying_agents' => (int) $s->paying_agents, 'commission_cents' => (int) $s->commission_cents,
                    'markup_cents' => (int) $s->markup_cents, 'adjustments_cents' => (int) $s->adjustments_cents, 'carried_in_cents' => (int) $s->carried_in_cents,
                    'payable_cents' => (int) $s->payable_cents, 'status' => $s->status, 'lines' => json_decode((string) $s->lines, true)]),
            'minimum_cents' => self::MINIMUM_PAYOUT_CENTS,
        ];
    }

    private function percentFor(Tenant $tenant, string $month): string
    {
        $p = DB::connection(Connections::control())->table('billing_partner_statements')->where('tenant_id', $tenant->id)->where('month', $month)->value('percent');

        return $p !== null ? (string) $p : $this->catalogue->commissionPercent(1);
    }
}
