<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Catalogue;
use Modules\Billing\Entitlements;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\AgentSubscription;
use Modules\Billing\Models\Package;
use Modules\Billing\Models\PlanPrice;
use Modules\Billing\Pricing;
use Modules\Billing\ProductFeatures;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

final class BillingController
{
    public function __construct(private readonly Catalogue $catalogue, private readonly Pricing $pricing) {}

    /** HQ: agent plans, commission tiers. */
    public function hq(): Response
    {
        return Inertia::render('hq/billing', [
            'plans' => AgentPlan::query()->orderBy('sort')->get()->map(fn (AgentPlan $p): array => $p->only(['ulid', 'key', 'name', 'description', 'list_cents', 'floor_cents', 'features', 'sort', 'active'])),
            'tiers' => DB::connection(Connections::control())->table('billing_commission_tiers')->orderBy('min_paying_agents')->get(['min_paying_agents', 'percent']),
            'features' => ProductFeatures::all(),
            'packages' => Package::query()->orderBy('monthly_cents')->get()->map(fn ($p): array => $p->only(['ulid', 'name', 'monthly_cents', 'yearly_cents', 'ailly_conversations', 'features', 'active'])),
        ]);
    }

    public function hqSavePlan(Request $request, ?AgentPlan $plan = null): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'regex:/^[a-z0-9_]{2,40}$/'], 'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'],
            'list' => ['required', 'numeric', 'min:1'], 'floor' => ['required', 'numeric', 'min:0'], 'features' => ['array'], 'features.*' => ['string'], 'sort' => ['nullable', 'integer'],
        ]);
        $this->catalogue->savePlan($plan, ['key' => $data['key'], 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'list_cents' => (int) round((float) $data['list'] * 100), 'floor_cents' => (int) round((float) $data['floor'] * 100),
            'features' => array_values($data['features'] ?? []), 'sort' => (int) ($data['sort'] ?? 0)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan saved. Price increases reach brokerages after 60 days.']);

        return back();
    }

    public function hqSaveTiers(Request $request): RedirectResponse
    {
        $data = $request->validate(['tiers' => ['required', 'array', 'min:1'], 'tiers.*.min_paying_agents' => ['required', 'integer', 'min:1'], 'tiers.*.percent' => ['required', 'numeric', 'min:0', 'max:100']]);
        $this->catalogue->saveTiers(array_values($data['tiers']));

        return back();
    }

    /** Brokerage owner: who pays, no-plan access, and agent prices. */
    public function settings(Request $request): Response
    {
        $user = $this->owner($request);
        $tenant = $request->attributes->get('tenant') ?? app(TenantContext::class)->current();

        return Inertia::render('settings/agent-plans', [
            'model' => app(Entitlements::class)->model(),
            'mayChoose' => app(Entitlements::class)->brokerMayChoose(),
            'agents' => User::query()->where('kind', UserKind::Agent)->where('status', 'active')->orderBy('first_name')->get()->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]),
            'policy' => (string) $tenant->getAttribute('no_plan_policy'),
            'payingAgents' => $this->pricing->payingAgents(),
            'plans' => AgentPlan::query()->where('active', true)->orderBy('sort')->get()->map(function (AgentPlan $p): array {
                $price = $this->pricing->priceFor($p);
                $row = PlanPrice::query()->where('agent_plan_id', $p->id)->first();

                return ['ulid' => $p->ulid, 'name' => $p->name, 'description' => $p->description, 'features' => $p->features,
                    'list_cents' => $this->catalogue->priceInForce($p, 'list'), 'floor_cents' => $this->catalogue->priceInForce($p, 'floor'),
                    'price_cents' => $price, 'offered' => $row === null || $row->offered, 'default' => $row !== null && $row->is_default,
                    'pending' => $row?->pending_from?->toDateString(), 'earnings' => $this->pricing->earningsPreview($p, $price)];
            }),
            'featureNames' => ProductFeatures::all(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $user = $this->owner($request);
        $data = $request->validate(['model' => ['required', 'in:brokerage_pays,agent_pays'], 'policy' => ['required', 'in:minimal,blocked']]);
        $this->pricing->configure($data['model'], $data['policy'], $user);

        return back();
    }

    public function savePrice(Request $request, AgentPlan $plan): RedirectResponse
    {
        $user = $this->owner($request);
        $data = $request->validate(['price' => ['required', 'numeric', 'min:0'], 'offered' => ['boolean'], 'default' => ['boolean']]);
        $this->pricing->setPrice($plan, (int) round((float) $data['price'] * 100), (bool) ($data['offered'] ?? true), $user);
        if ($data['default'] ?? false) {
            $this->pricing->setDefault($plan);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price saved. Increases reach existing agents after 30 days.']);

        return back();
    }

    /** Agent: plans at the brokerage's price. */
    public function plans(Request $request, Entitlements $entitlements): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $current = $entitlements->activePlan($user);

        return Inertia::render('billing/plans', [
            'model' => $entitlements->model(),
            'blocked' => $entitlements->blocked($user),
            'current' => $current?->name,
            'wanted' => (string) $request->query('feature', ''),
            'plans' => AgentPlan::query()->where('active', true)->orderBy('sort')->get()
                ->filter(fn (AgentPlan $p): bool => $this->pricing->offered($p))
                ->map(fn (AgentPlan $p): array => ['ulid' => $p->ulid, 'name' => $p->name, 'description' => $p->description, 'features' => $p->features,
                    'price_cents' => $this->pricing->priceFor($p), 'list_cents' => $this->catalogue->priceInForce($p, 'list')])->values(),
            'featureNames' => ProductFeatures::all(),
            'paymentsOpen' => $entitlements->model() === 'agent_pays' && app(PaymentGateway::class)->ready(),
            'subscription' => ($sub = AgentSubscription::query()->where('user_id', $user->id)->whereIn('status', ['active', 'grace'])->orderByDesc('id')->first()) !== null
                ? ['status' => $sub->status, 'grace_until' => $sub->grace_until?->toDateString(), 'sponsored' => (bool) $sub->getAttribute('sponsored'), 'price_cents' => $sub->price_cents] : null,
        ]);
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isKind(UserKind::Owner), 403);

        return $user;
    }
}
