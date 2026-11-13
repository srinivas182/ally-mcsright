<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\AgentSubscription;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Payouts\AgentBilling;

/**
 * Agents paying RightAlly directly (specs/core/07): checkout at the brokerage price, activation from
 * the gateway's events, a 7-day grace period after a failed payment, card and invoice portal, and
 * agents fully sponsored by their brokerage.
 */
final class AgentPayments
{
    public const GRACE_DAYS = 7;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly Pricing $pricing,
        private readonly PaymentSettings $settings,
        private readonly TenantContext $tenants,
    ) {}

    public function checkout(User $agent, AgentPlan $plan, string $returnBase): string
    {
        if (! $agent->isKind(UserKind::Agent) || ! $plan->active || ! $this->pricing->offered($plan)) {
            throw ValidationException::withMessages(['plan' => 'Choose a plan your brokerage offers.']);
        }
        if (app(Entitlements::class)->model() !== 'agent_pays') {
            throw ValidationException::withMessages(['plan' => 'Your brokerage pays for your plan.']);
        }
        $price = $this->pricing->priceFor($plan);
        app(AuditLogger::class)->record('billing.checkout.started', metadata: ['plan' => $plan->key, 'price_cents' => $price], label: $agent->name);

        return $this->gateway->subscriptionCheckout($agent->email, "RightAlly {$plan->name} — ".$this->tenants->current()->name, $price, 'month',
            $returnBase.'/plans?checkout=success', $returnBase.'/plans?checkout=cancelled',
            ['tenant_id' => (string) $this->tenants->id(), 'user_id' => (string) $agent->id, 'agent_plan_id' => (string) $plan->id, 'price_cents' => (string) $price],
            $this->settings->automaticTax());
    }

    /**
     * Applies one gateway event, once. Returns false when it was already processed or not ours.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): bool
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $object = (array) ($event['data']['object'] ?? []);
        $meta = (array) ($object['metadata'] ?? ($object['subscription_details']['metadata'] ?? []));
        $control = DB::connection(Connections::control());
        if ($id !== '' && in_array($type, ['charge.refunded', 'account.updated'], true) && ! $control->table('billing_gateway_events')->where('id', $id)->exists()) {
            $type === 'charge.refunded'
                ? app(PartnerEarnings::class)->recordRefund((string) ($object['invoice'] ?? ''), (int) ($object['amount_refunded'] ?? 0))
                : app(PartnerEarnings::class)->accountUpdated($object);
            $control->table('billing_gateway_events')->insert(['id' => $id, 'type' => $type, 'processed_at' => now()]);

            return true;
        }
        if ($id === '' || ! isset($meta['tenant_id']) || $control->table('billing_gateway_events')->where('id', $id)->exists()) {
            return false;
        }
        if (($meta['kind'] ?? '') === 'agent_invoice' && $type === 'checkout.session.completed') {
            $tenant = Tenant::query()->find((int) $meta['tenant_id']);
            if ($tenant !== null) {
                $this->tenants->runAs($tenant, fn () => app(AgentBilling::class)->paidOnline((string) ($meta['invoice'] ?? ''), (int) ($object['amount_total'] ?? 0), $id));
            }
            $control->table('billing_gateway_events')->insert(['id' => $id, 'type' => $type, 'processed_at' => now()]);

            return true;
        }
        if (($meta['kind'] ?? '') === 'brokerage') {
            app(BrokeragePayments::class)->apply($type, $object, $meta);
            $control->table('billing_gateway_events')->insert(['id' => $id, 'type' => $type, 'processed_at' => now()]);

            return true;
        }
        if (! isset($meta['user_id'])) {
            return false;
        }

        $tenant = Tenant::query()->find((int) $meta['tenant_id']);
        if ($tenant === null) {
            return false;
        }
        $this->tenants->runAs($tenant, function () use ($type, $object, $meta): void {
            $userId = (int) $meta['user_id'];
            $subRef = (string) ($object['subscription'] ?? $object['id'] ?? '');
            $current = AgentSubscription::query()->where('user_id', $userId)->where('status', '!=', 'ended')->orderByDesc('id')->first();

            match ($type) {
                'checkout.session.completed' => $this->activate($userId, (int) $meta['agent_plan_id'], (int) $meta['price_cents'], $subRef, (string) ($object['customer'] ?? '')),
                'invoice.paid' => $current !== null ? $this->paid($current, $object) : null,
                'invoice.payment_failed' => $current !== null && $current->status === 'active' ? $this->startGrace($current) : null,
                'customer.subscription.deleted' => $current?->update(['status' => 'ended', 'grace_until' => null]),
                default => null,
            };
        });
        $control->table('billing_gateway_events')->insert(['id' => $id, 'type' => $type, 'processed_at' => now()]);

        return true;
    }

    /**
     * Daily: plans whose 7-day grace period has passed end; the brokerage's no-plan level applies.
     */
    public function endExpiredGrace(): int
    {
        $count = 0;
        foreach (AgentSubscription::query()->where('status', 'grace')->where('grace_until', '<=', CarbonImmutable::now())->get() as $sub) {
            $sub->update(['status' => 'ended']);
            if ($sub->gateway_ref !== null && ! $sub->sponsored) {
                $this->gateway->cancelAtPeriodEnd($sub->gateway_ref);
            }
            app(AuditLogger::class)->asAutomation('billing_grace', $sub->user_id, 'End plan after unpaid grace period',
                fn () => app(AuditLogger::class)->record('billing.subscription.ended', User::query()->find($sub->user_id), metadata: ['reason' => 'unpaid'], severity: 'warning'));
            $count++;
        }

        return $count;
    }

    /** The brokerage pays the whole plan for this agent (no card needed from the agent). */
    public function sponsor(User $agent, AgentPlan $plan, User $by): AgentSubscription
    {
        if (! $by->isKind(UserKind::Owner) || ! $agent->isKind(UserKind::Agent)) {
            throw ValidationException::withMessages(['agent' => 'Owners can sponsor their agents.']);
        }
        $price = $this->pricing->priceFor($plan);
        AgentSubscription::query()->where('user_id', $agent->id)->where('status', '!=', 'ended')->update(['status' => 'ended']);
        $sub = AgentSubscription::query()->create(['user_id' => $agent->id, 'agent_plan_id' => $plan->id, 'status' => 'active', 'price_cents' => $price,
            'subsidy_cents' => $price, 'sponsored' => true, 'gateway_ref' => 'sponsored', 'current_period_end' => CarbonImmutable::now()->addMonth()]);
        app(AuditLogger::class)->record('billing.subscription.sponsored', $agent, metadata: ['plan' => $plan->key, 'price_cents' => $price], severity: 'notice', label: $agent->name);
        app(Entitlements::class)->forget();

        return $sub;
    }

    public function portal(User $agent, string $returnUrl): string
    {
        $customer = AgentSubscription::query()->where('user_id', $agent->id)->whereNotNull('customer_ref')->orderByDesc('id')->value('customer_ref');
        if ($customer === null || $customer === '') {
            throw ValidationException::withMessages(['portal' => 'There is no card on file yet.']);
        }

        return $this->gateway->portal((string) $customer, $returnUrl);
    }

    private function activate(int $userId, int $planId, int $priceCents, string $subRef, string $customer): void
    {
        AgentSubscription::query()->where('user_id', $userId)->where('status', '!=', 'ended')->update(['status' => 'ended']);
        AgentSubscription::query()->create(['user_id' => $userId, 'agent_plan_id' => $planId, 'status' => 'active', 'price_cents' => $priceCents,
            'gateway_ref' => $subRef, 'customer_ref' => $customer, 'current_period_end' => CarbonImmutable::now()->addMonth()]);
        app(AuditLogger::class)->record('billing.subscription.started', User::query()->find($userId), metadata: ['plan_id' => $planId, 'price_cents' => $priceCents], severity: 'notice');
        app(Entitlements::class)->forget();
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function paid(AgentSubscription $sub, array $invoice): void
    {
        $sub->update(['status' => 'active', 'grace_until' => null,
            'current_period_end' => isset($invoice['period_end']) ? CarbonImmutable::createFromTimestamp((int) $invoice['period_end']) : $sub->current_period_end]);
        app(PartnerEarnings::class)->recordCollection($sub, $invoice);
    }

    private function startGrace(AgentSubscription $sub): void
    {
        $sub->update(['status' => 'grace', 'grace_until' => CarbonImmutable::now()->addDays(self::GRACE_DAYS)]);
        $agent = User::query()->find($sub->user_id);
        $agent?->notify(new PaymentFailed($sub->grace_until));
        app(AuditLogger::class)->record('billing.payment.failed', $agent, metadata: ['grace_until' => $sub->grace_until?->toDateString()], severity: 'warning');
    }
}
