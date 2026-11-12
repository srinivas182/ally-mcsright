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
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\PaymentSettings;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Models\Tenant;
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
}
