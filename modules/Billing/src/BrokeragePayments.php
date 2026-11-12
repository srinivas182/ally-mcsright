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
use Modules\Billing\Models\Package;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * The brokerage side (specs/core/07): HQ packages and the brokerage's flat fee, read-only after an
 * unpaid grace period (for owners and staff only — agents who pay for themselves keep working),
 * subsidies billed to the brokerage monthly, and partner join codes.
 */
final class BrokeragePayments
{
    public const GRACE_DAYS = 7;

    public function __construct(private readonly PaymentGateway $gateway, private readonly PaymentSettings $settings, private readonly TenantContext $tenants) {}

    /**
     * @param  array{name: string, monthly_cents: int, yearly_cents: int, ailly_conversations: int, features: list<string>, active?: bool}  $data
     */
    public function savePackage(?Package $package, array $data): Package
    {
        $unknown = array_diff($data['features'], array_keys(ProductFeatures::all()));
        if ($unknown !== [] || $data['monthly_cents'] <= 0 || $data['yearly_cents'] <= 0) {
            throw ValidationException::withMessages(['package' => 'Give the package prices and known features.']);
        }
        $package ??= new Package;
        $package->fill($data + ['active' => true])->save();
        app(AuditLogger::class)->record('billing.package.saved', metadata: ['name' => $package->name, 'monthly_cents' => $package->monthly_cents, 'yearly_cents' => $package->yearly_cents], severity: 'notice', label: $package->name);

        return $package;
    }

    public function checkout(User $owner, Package $package, string $interval, string $returnBase): string
    {
        if (! $owner->isKind(UserKind::Owner) || ! $package->active || ! in_array($interval, ['month', 'year'], true)) {
            throw ValidationException::withMessages(['package' => 'Choose an available package and billing period.']);
        }
        $price = $interval === 'year' ? $package->yearly_cents : $package->monthly_cents;

        return $this->gateway->subscriptionCheckout($owner->email, "RightAlly {$package->name} — ".$this->tenants->current()->name, $price, $interval,
            $returnBase.'/settings/billing?checkout=success', $returnBase.'/settings/billing?checkout=cancelled',
            ['kind' => 'brokerage', 'tenant_id' => (string) $this->tenants->id(), 'package_id' => (string) $package->id, 'interval' => $interval, 'price_cents' => (string) $price],
            $this->settings->automaticTax());
    }

    /**
     * Applies a gateway event for a brokerage subscription.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $meta
     */
    public function apply(string $type, array $object, array $meta): void
    {
        $tenantId = (int) $meta['tenant_id'];
        $db = DB::connection(Connections::control());
        $current = $db->table('billing_tenant_subscriptions')->where('tenant_id', $tenantId)->where('status', '!=', 'ended')->orderByDesc('id')->first();

        if ($type === 'checkout.session.completed') {
            $db->table('billing_tenant_subscriptions')->where('tenant_id', $tenantId)->where('status', '!=', 'ended')->update(['status' => 'ended', 'updated_at' => now()]);
            $db->table('billing_tenant_subscriptions')->insert([
                'tenant_id' => $tenantId, 'package_id' => (int) $meta['package_id'], 'interval' => (string) $meta['interval'], 'status' => 'active',
                'price_cents' => (int) $meta['price_cents'], 'gateway_ref' => (string) ($object['subscription'] ?? ''), 'customer_ref' => (string) ($object['customer'] ?? ''),
                'current_period_end' => CarbonImmutable::now()->add((string) $meta['interval'] === 'year' ? '1 year' : '1 month'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Tenant::query()->whereKey($tenantId)->update(['billing_package_id' => (int) $meta['package_id']]);
            app(AuditLogger::class)->record('billing.brokerage.subscribed', metadata: ['package_id' => (int) $meta['package_id'], 'interval' => $meta['interval']], severity: 'notice', tenantId: $tenantId);

            return;
        }
        if ($current === null) {
            return;
        }
        match ($type) {
            'invoice.paid' => $db->table('billing_tenant_subscriptions')->where('id', $current->id)->update(['status' => 'active', 'grace_until' => null, 'updated_at' => now()]),
            'invoice.payment_failed' => $current->status === 'active'
                ? $db->table('billing_tenant_subscriptions')->where('id', $current->id)->update(['status' => 'grace', 'grace_until' => CarbonImmutable::now()->addDays(self::GRACE_DAYS), 'updated_at' => now()])
                : null,
            'customer.subscription.deleted' => $db->table('billing_tenant_subscriptions')->where('id', $current->id)->update(['status' => 'ended', 'updated_at' => now()]),
            default => null,
        };
    }

    public function endExpiredGrace(): int
    {
        return DB::connection(Connections::control())->table('billing_tenant_subscriptions')->where('status', 'grace')
            ->where('grace_until', '<=', CarbonImmutable::now())->update(['status' => 'ended', 'updated_at' => now()]);
    }

    /**
     * Read-only for owners and staff when the brokerage chose a package and its fee is unpaid past grace.
     */
    public function readOnly(?int $tenantId = null): bool
    {
        $tenantId ??= $this->tenants->check() ? $this->tenants->id() : null;
        if ($tenantId === null || Tenant::query()->whereKey($tenantId)->value('billing_package_id') === null) {
            return false;
        }
        $latest = DB::connection(Connections::control())->table('billing_tenant_subscriptions')->where('tenant_id', $tenantId)->orderByDesc('id')->value('status');

        return $latest === 'ended';
    }

    /**
     * @return array{status: string, interval: string, price_cents: int, package: string|null, grace_until: string|null, customer: string|null}|null
     */
    public function current(): ?array
    {
        $row = DB::connection(Connections::control())->table('billing_tenant_subscriptions')->where('tenant_id', $this->tenants->id())->orderByDesc('id')->first();

        return $row === null ? null : ['status' => (string) $row->status, 'interval' => (string) $row->interval, 'price_cents' => (int) $row->price_cents,
            'package' => Package::query()->whereKey((int) $row->package_id)->value('name'), 'grace_until' => $row->grace_until !== null ? substr((string) $row->grace_until, 0, 10) : null,
            'customer' => $row->customer_ref !== null && $row->customer_ref !== '' ? (string) $row->customer_ref : null];
    }

    /**
     * The brokerage pays part of an agent's plan; the agent's card is charged the rest.
     */
    public function setSubsidy(User $agent, int $cents, User $owner): AgentSubscription
    {
        if (! $owner->isKind(UserKind::Owner)) {
            throw ValidationException::withMessages(['subsidy' => 'Owners set subsidies.']);
        }
        if (($this->current()['customer'] ?? null) === null) {
            throw ValidationException::withMessages(['subsidy' => 'Add the brokerage\'s card first (Settings › Billing), so subsidies can be charged to the brokerage.']);
        }
        $sub = AgentSubscription::query()->where('user_id', $agent->id)->whereIn('status', ['active', 'grace'])->orderByDesc('id')->first();
        if ($sub === null || $sub->sponsored) {
            throw ValidationException::withMessages(['subsidy' => 'This agent needs an active plan they pay for. To pay the whole plan, sponsor it instead.']);
        }
        if ($cents < 0 || $cents >= $sub->price_cents) {
            throw ValidationException::withMessages(['subsidy' => 'A subsidy is less than the plan price ($'.number_format($sub->price_cents / 100, 2).').']);
        }
        $sub->update(['subsidy_cents' => $cents]);
        $plan = AgentPlan::query()->find($sub->agent_plan_id);
        if ($sub->gateway_ref !== null && $sub->gateway_ref !== 'sponsored') {
            $this->gateway->changeAmount($sub->gateway_ref, $sub->price_cents - $cents, 'RightAlly '.($plan->name ?? 'plan').' — '.$this->tenants->current()->name, 'month');
        }
        app(AuditLogger::class)->record('billing.subsidy.set', $agent, metadata: ['subsidy_cents' => $cents, 'agent_pays_cents' => $sub->price_cents - $cents], severity: 'notice', label: $agent->name);

        return $sub;
    }

    /**
     * Monthly: subsidies and sponsored plans added to the brokerage's next invoice, once per month.
     */
    public function billSubsidies(?CarbonImmutable $month = null): int
    {
        $month ??= CarbonImmutable::now()->startOfMonth();
        $customer = $this->current()['customer'] ?? null;
        $key = 'subsidies:'.$this->tenants->id().':'.$month->format('Y-m');
        if ($customer === null || DB::connection(Connections::control())->table('billing_gateway_events')->where('id', $key)->exists()) {
            return 0;
        }
        $total = 0;
        foreach (AgentSubscription::query()->whereIn('status', ['active', 'grace'])->get() as $sub) {
            $total += $sub->sponsored ? $sub->price_cents : $sub->subsidy_cents;
        }
        if ($total > 0) {
            $this->gateway->addInvoiceItem($customer, $total, 'Agent plan subsidies and sponsorships, '.$month->format('F Y'));
        }
        DB::connection(Connections::control())->table('billing_gateway_events')->insert(['id' => $key, 'type' => 'subsidy_run', 'processed_at' => now()]);

        return $total;
    }

    public function partnerCode(): string
    {
        $tenant = $this->tenants->current();
        $code = $tenant->getAttribute('partner_code');
        if (is_string($code) && $code !== '') {
            return $code;
        }
        do {
            $code = Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', (string) $tenant->slug) ?: 'RA', 0, 4)).'-'.Str::upper(Str::random(4));
        } while (Tenant::query()->where('partner_code', $code)->exists());
        Tenant::query()->whereKey($tenant->id)->update(['partner_code' => $code]);
        $this->tenants->set($tenant->fresh() ?? $tenant);

        return $code;
    }
}
