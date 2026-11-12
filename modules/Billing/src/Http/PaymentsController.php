<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\AgentPayments;
use Modules\Billing\BrokeragePayments;
use Modules\Billing\Catalogue;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\Package;
use Modules\Billing\PartnerEarnings;
use Modules\Billing\PaymentSettings;
use Modules\Billing\Pricing;
use Modules\Billing\ProductFeatures;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

final class PaymentsController
{
    /** HQ: payment settings. */
    public function hq(PaymentSettings $settings): Response
    {
        return Inertia::render('hq/payments', ['settings' => $settings->summary(), 'webhookUrl' => 'https://'.config('core.hq_host').'/billing/webhook']);
    }

    public function saveKeys(Request $request, PaymentSettings $settings): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:test,live'], 'publishable' => ['required', 'string', 'max:255'], 'secret' => ['required', 'string', 'max:255'], 'webhook_secret' => ['required', 'string', 'max:255']]);
        $settings->saveKeys($data['mode'], trim($data['publishable']), trim($data['secret']), trim($data['webhook_secret']), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => ucfirst($data['mode']).' keys saved. They will not be shown again.']);

        return back();
    }

    public function setMode(Request $request, PaymentSettings $settings): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:test,live']]);
        $settings->setMode($data['mode'], self::user($request));

        return back();
    }

    public function setOptions(Request $request, PaymentSettings $settings): RedirectResponse
    {
        $data = $request->validate(['agent_pays_default' => ['required', 'boolean'], 'automatic_tax' => ['required', 'boolean']]);
        $settings->setOptions((bool) $data['agent_pays_default'], (bool) $data['automatic_tax'], self::user($request));

        return back();
    }

    /** HQ: "this brokerage pays for its agents" (it takes on the liability). */
    public function exception(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'reason' => ['required_if:enabled,true', 'nullable', 'string', 'min:10', 'max:300']]);
        $tenant->forceFill(['billing_exception' => (bool) $data['enabled'], 'billing_exception_reason' => $data['enabled'] ? $data['reason'] : null])->save();
        $logger = app(AuditLogger::class);
        foreach ([$tenant->id, null] as $chain) {
            $logger->record('billing.exception.'.($data['enabled'] ? 'enabled' : 'disabled'), $tenant, metadata: ['reason' => $data['reason'] ?? null], severity: 'warning', label: $tenant->name, tenantId: $chain);
        }

        return back();
    }

    /** Agent: start checkout at the brokerage price. */
    public function checkout(Request $request, AgentPlan $plan, AgentPayments $payments): HttpResponse
    {
        return Inertia::location($payments->checkout(self::user($request), $plan, $request->getSchemeAndHttpHost()));
    }

    public function portal(Request $request, AgentPayments $payments): HttpResponse
    {
        return Inertia::location($payments->portal(self::user($request), $request->getSchemeAndHttpHost().'/plans'));
    }

    /** Owner: the brokerage pays this agent's whole plan. */
    public function sponsor(Request $request, AgentPayments $payments): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'plan' => ['required', 'string', 'size:26']]);
        $payments->sponsor(User::query()->where('ulid', $data['agent'])->firstOrFail(), AgentPlan::query()->where('ulid', $data['plan'])->firstOrFail(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan sponsored.']);

        return back();
    }

    /** Payment provider events (signed). */
    public function webhook(Request $request, PaymentGateway $gateway, AgentPayments $payments): JsonResponse
    {
        try {
            $event = $gateway->verifyEvent((string) $request->getContent(), (string) $request->header('Stripe-Signature', ''));
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        return response()->json(['processed' => $payments->handle($event)]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Owner: the brokerage's package, card, invoices and partner code. */
    public function billing(Request $request, BrokeragePayments $brokerage): Response
    {
        $user = self::user($request);
        abort_unless($user->isKind(UserKind::Owner), 403);

        return Inertia::render('settings/billing', [
            'packages' => Package::query()->where('active', true)->orderBy('monthly_cents')->get()
                ->map(fn ($p): array => $p->only(['ulid', 'name', 'monthly_cents', 'yearly_cents', 'ailly_conversations', 'features'])),
            'current' => $brokerage->current(),
            'readOnly' => $brokerage->readOnly(),
            'partnerCode' => $brokerage->partnerCode(),
            'featureNames' => ProductFeatures::all(),
        ]);
    }

    public function brokerageCheckout(Request $request, BrokeragePayments $brokerage): HttpResponse
    {
        $data = $request->validate(['package' => ['required', 'string', 'size:26'], 'interval' => ['required', 'in:month,year']]);
        $package = Package::query()->where('ulid', $data['package'])->firstOrFail();

        return Inertia::location($brokerage->checkout(self::user($request), $package, $data['interval'], $request->getSchemeAndHttpHost()));
    }

    public function brokeragePortal(Request $request, BrokeragePayments $brokerage, PaymentGateway $gateway): HttpResponse
    {
        abort_unless(self::user($request)->isKind(UserKind::Owner), 403);
        $customer = $brokerage->current()['customer'] ?? null;
        abort_if($customer === null, 404);

        return Inertia::location($gateway->portal($customer, $request->getSchemeAndHttpHost().'/settings/billing'));
    }

    public function subsidy(Request $request, BrokeragePayments $brokerage): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'amount' => ['required', 'numeric', 'min:0']]);
        $brokerage->setSubsidy(User::query()->where('ulid', $data['agent'])->firstOrFail(), (int) round((float) $data['amount'] * 100), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Subsidy saved. It is added to the brokerage\'s invoice each month.']);

        return back();
    }

    /** HQ: brokerage packages. */
    public function savePackage(Request $request, BrokeragePayments $brokerage, ?Package $package = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'monthly' => ['required', 'numeric', 'min:1'], 'yearly' => ['required', 'numeric', 'min:1'],
            'ailly' => ['required', 'integer', 'min:0'], 'features' => ['array'], 'features.*' => ['string']]);
        $brokerage->savePackage($package, ['name' => $data['name'], 'monthly_cents' => (int) round((float) $data['monthly'] * 100),
            'yearly_cents' => (int) round((float) $data['yearly'] * 100), 'ailly_conversations' => (int) $data['ailly'], 'features' => array_values($data['features'] ?? [])]);

        return back();
    }

    /** Public: "My brokerage is a partner" — a brokerage's prices from its join code. */
    public function partnerLookup(string $code, TenantContext $tenants): JsonResponse
    {
        $tenant = Tenant::query()->where('partner_code', mb_strtoupper(trim($code)))->where('status', 'active')->first();
        if ($tenant === null) {
            return response()->json(['message' => 'We couldn\'t find a partner brokerage with that code.'], 404);
        }

        return response()->json($tenants->runAs($tenant, function () use ($tenant): array {
            $pricing = app(Pricing::class);
            $catalogue = app(Catalogue::class);

            return ['brokerage' => $tenant->name, 'plans' => AgentPlan::query()->where('active', true)->orderBy('sort')->get()
                ->filter(fn (AgentPlan $p): bool => $pricing->offered($p))
                ->map(fn (AgentPlan $p): array => ['name' => $p->name, 'price_cents' => $pricing->priceFor($p), 'list_cents' => $catalogue->priceInForce($p, 'list')])->values()];
        }));
    }

    /** Owner: partner earnings — payouts setup, agents by plan, this month, statements. */
    public function partner(Request $request, PartnerEarnings $earnings): Response
    {
        abort_unless(self::user($request)->isKind(UserKind::Owner), 403);

        return Inertia::render('settings/partner', $earnings->dashboard());
    }

    public function connect(Request $request, PartnerEarnings $earnings): HttpResponse
    {
        return Inertia::location($earnings->onboarding(self::user($request), $request->getSchemeAndHttpHost()));
    }
}
