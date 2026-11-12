<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\User;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\AgentSubscription;
use Modules\Billing\Models\Package;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;

/**
 * Which features a person may use (specs/core/07 and 03).
 *
 * - "Brokerage pays" (every existing brokerage): everyone gets the brokerage's package (or everything
 *   when no package is set) — no change until the brokerage switches to agent-pays.
 * - "Agents pay": owners and staff get the brokerage's package; an agent (and their assistant) gets
 *   their plan's features while the plan is active or in its grace period; without a plan, the
 *   brokerage's choice applies: minimal features or blocked (choose a plan first).
 */
final class Entitlements
{
    /** @var array<int, list<string>> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function allows(User $user, string $feature): bool
    {
        return in_array($feature, $this->features($user), true);
    }

    public function blocked(User $user): bool
    {
        return $this->model() === 'agent_pays' && $this->isAgentSide($user) && $this->activePlan($user) === null
            && $this->tenants->current()->getAttribute('no_plan_policy') === 'blocked';
    }

    /**
     * @return list<string>
     */
    public function features(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }
        $package = $this->packageFeatures();
        if ($this->model() !== 'agent_pays' || ! $this->isAgentSide($user)) {
            return $this->cache[$user->id] = $package;
        }

        $plan = $this->activePlan($user);
        if ($plan !== null) {
            return $this->cache[$user->id] = array_values(array_unique([...ProductFeatures::MINIMAL, ...$plan->features]));
        }

        return $this->cache[$user->id] = $this->tenants->current()->getAttribute('no_plan_policy') === 'blocked' ? [] : ProductFeatures::MINIMAL;
    }

    public function activePlan(User $user): ?AgentPlan
    {
        $payer = $user->kind === UserKind::Assistant && $user->assisted_user_id !== null ? $user->assisted_user_id : $user->id;
        $sub = AgentSubscription::query()->where('user_id', $payer)->whereIn('status', ['active', 'grace'])->orderByDesc('id')->first();

        return $sub !== null ? AgentPlan::query()->find($sub->agent_plan_id) : null;
    }

    public function model(): string
    {
        return $this->tenants->check() ? (string) ($this->tenants->current()->getAttribute('billing_model') ?? 'brokerage_pays') : 'brokerage_pays';
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function isAgentSide(User $user): bool
    {
        return $user->isKind(UserKind::Agent, UserKind::Assistant);
    }

    /**
     * @return list<string>
     */
    private function packageFeatures(): array
    {
        $id = $this->tenants->check() ? $this->tenants->current()->getAttribute('billing_package_id') : null;
        $package = $id !== null ? Package::query()->whereKey((int) $id)->first() : null;

        return $package !== null ? array_values(array_unique([...ProductFeatures::MINIMAL, ...$package->features])) : array_keys(ProductFeatures::all());
    }
}
