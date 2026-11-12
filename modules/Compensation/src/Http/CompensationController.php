<?php

declare(strict_types=1);

namespace Modules\Compensation\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Credits;
use Modules\Compensation\Enums\DealClass;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Enums\Side;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\FeeCatalogue;
use Modules\Compensation\Insights;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\Models\PlanAssignment;
use Modules\Compensation\Models\PlanVersion;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Ledger\Money\Money;
use Throwable;

/**
 * Compensation screens (specs/compensation/01, Sprint 9B-2): plans and versions, assignments, fee
 * catalogue, credits, simulator, profitability and what-if, and each agent's "My plan".
 */
final class CompensationController
{
    public function __construct(private readonly ManagePlans $plans) {}

    public function index(Request $request): Response
    {
        $user = $this->can($request, 'compensation.plans.view');

        return Inertia::render('compensation/plans', [
            'plans' => Plan::query()->with('versions')->orderByDesc('is_default')->orderBy('name')->get()->map(fn (Plan $p): array => [
                'ulid' => $p->ulid, 'name' => $p->name, 'type' => $p->type->value, 'default' => $p->is_default, 'status' => $p->status->value, 'description' => $p->description,
                'agents' => PlanAssignment::query()->where('plan_id', $p->id)->whereNull('effective_to')->count(),
                'versions' => $p->versions->sortByDesc('version')->map(fn (PlanVersion $v): array => [
                    'ulid' => $v->ulid, 'version' => $v->version, 'status' => $v->status->value, 'effective_from' => $v->effective_from?->toDateString(),
                    'rules' => $v->rules, 'note' => $v->change_note, 'published_at' => $v->published_at?->toIso8601String(),
                ])->values(),
            ]),
            'agents' => User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->where('status', 'active')->orderBy('first_name')->get()
                ->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name, 'plan' => $this->currentPlanName($u->id)]),
            'fees' => DB::connection(Connections::cell())->table('comp_fees')->orderBy('name')->get(['code', 'name']),
            'canManage' => $user->can('compensation.plans.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->can($request, 'compensation.plans.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'type' => ['required', Rule::enum(PlanType::class)], 'rules' => ['required', 'array'], 'description' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(fn () => $this->plans->create(PlanType::from($data['type']), $data['name'], $data['rules'], $user->id, $data['description'] ?? null), 'Plan saved as a draft.');
    }

    public function saveDraft(Request $request, Plan $plan): RedirectResponse
    {
        $this->can($request, 'compensation.plans.manage');
        $data = $request->validate(['rules' => ['required', 'array']]);

        return $this->attempt(fn () => $this->plans->saveDraft($plan, $data['rules']), 'Draft saved.');
    }

    public function publish(Request $request, PlanVersion $version): RedirectResponse
    {
        $user = $this->can($request, 'compensation.plans.manage');
        $data = $request->validate(['effective_from' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(fn () => $this->plans->publish($version, CarbonImmutable::parse($data['effective_from']), $user->id, $data['note'] ?? null), 'Published. Pending deals were recalculated.');
    }

    public function makeDefault(Request $request, Plan $plan): RedirectResponse
    {
        $this->can($request, 'compensation.plans.manage');

        return $this->attempt(fn () => $this->plans->setDefault($plan), 'Default plan changed.');
    }

    public function archive(Request $request, Plan $plan): RedirectResponse
    {
        $this->can($request, 'compensation.plans.manage');

        return $this->attempt(fn () => $this->plans->archive($plan), 'Plan archived.');
    }

    public function assign(Request $request): RedirectResponse
    {
        $user = $this->can($request, 'compensation.plans.manage');
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'plan' => ['required', 'string', 'size:26'], 'from' => ['required', 'date']]);
        $agent = User::query()->where('ulid', $data['agent'])->firstOrFail();
        $plan = Plan::query()->where('ulid', $data['plan'])->firstOrFail();

        return $this->attempt(fn () => $this->plans->assign($agent->id, $plan, CarbonImmutable::parse($data['from']), $user->id), "{$agent->name} moved to {$plan->name}.");
    }

    public function fees(Request $request): Response
    {
        $user = $this->can($request, 'compensation.plans.view');
        $db = DB::connection(Connections::cell());

        return Inertia::render('compensation/fees', [
            'fees' => $db->table('comp_fees')->orderBy('name')->get()->map(fn ($f): array => [
                'code' => $f->code, 'name' => $f->name,
                'versions' => $db->table('comp_fee_versions')->where('fee_id', $f->id)->orderByDesc('version')->get()->map(fn ($v): array => (array) $v)->all(),
            ]),
            'canManage' => $user->can('compensation.plans.manage'),
        ]);
    }

    public function publishFee(Request $request, FeeCatalogue $catalogue): RedirectResponse
    {
        $user = $this->can($request, 'compensation.plans.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z0-9_]{2,40}$/'], 'name' => ['required', 'string', 'max:80'], 'effective_from' => ['required', 'date'],
            'stage' => ['required', 'in:pre,post'], 'calc' => ['required', 'in:flat,percent'], 'amount' => ['required', 'numeric', 'min:0'],
            'charged_to' => ['required', 'in:agent,brokerage'], 'payable_to' => ['required', 'in:brokerage,franchisor,vendor'],
            'counts_toward_cap' => ['boolean'], 'revenue_share_eligible' => ['boolean'], 'annual_cap' => ['nullable', 'numeric', 'min:0'],
        ]);
        $definition = [
            'stage' => $data['stage'], 'calc' => $data['calc'],
            'amount' => $data['calc'] === 'flat' ? (int) round((float) $data['amount'] * 100) : (string) $data['amount'],
            'charged_to' => $data['charged_to'], 'payable_to' => $data['payable_to'],
            'counts_toward_cap' => (bool) ($data['counts_toward_cap'] ?? false), 'revenue_share_eligible' => (bool) ($data['revenue_share_eligible'] ?? true),
            'annual_cap_cents' => isset($data['annual_cap']) ? (int) round((float) $data['annual_cap'] * 100) : null,
        ];

        return $this->attempt(fn () => $catalogue->publish($data['code'], $data['name'], CarbonImmutable::parse($data['effective_from']), $definition, $user), 'Fee version published.');
    }

    public function credits(Request $request): Response
    {
        $this->can($request, 'compensation.credits.grant');
        $db = DB::connection(Connections::cell());

        return Inertia::render('compensation/credits', [
            'credits' => $db->table('comp_credits')->orderByDesc('id')->limit(200)->get()->map(fn ($c): array => [
                'ulid' => $c->ulid, 'agent' => User::query()->whereKey((int) $c->agent_user_id)->value('first_name').' '.User::query()->whereKey((int) $c->agent_user_id)->value('last_name'), 'kind' => $c->kind, 'name' => $c->name,
                'amount_cents' => (int) $c->amount_cents, 'remaining_cents' => (int) $c->remaining_cents, 'expires_on' => $c->expires_on,
            ]),
            'agents' => User::query()->where('kind', UserKind::Agent)->where('status', 'active')->orderBy('first_name')->get()->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]),
        ]);
    }

    public function grantCredit(Request $request, Credits $credits): RedirectResponse
    {
        $user = $this->can($request, 'compensation.credits.grant');
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'kind' => ['required', Rule::in(Credits::KINDS)], 'name' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:0.01'], 'expires_on' => ['nullable', 'date']]);
        $agent = User::query()->where('ulid', $data['agent'])->firstOrFail();

        return $this->attempt(fn () => $credits->grant($agent->id, $data['kind'], $data['name'], (int) round((float) $data['amount'] * 100),
            isset($data['expires_on']) ? CarbonImmutable::parse($data['expires_on']) : null, $user), "Credit given to {$agent->name}.");
    }

    public function simulator(Request $request): Response
    {
        $this->can($request, 'compensation.plans.view');

        return Inertia::render('compensation/simulator', [
            'agents' => User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->where('status', 'active')->orderBy('first_name')->get()->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]),
            'dealClasses' => array_map(fn (DealClass $c): string => $c->value, DealClass::cases()),
        ]);
    }

    public function simulate(Request $request, CommissionService $service): JsonResponse
    {
        $this->can($request, 'compensation.plans.view');
        $data = $request->validate([
            'gci' => ['required', 'numeric', 'min:0.01'], 'sale_price' => ['required', 'numeric', 'min:0'], 'closing_date' => ['required', 'date'],
            'side' => ['required', Rule::enum(Side::class)], 'deal_class' => ['required', Rule::enum(DealClass::class)],
            'agents' => ['required', 'array', 'min:1', 'max:6'], 'agents.*.ulid' => ['required', 'string', 'size:26'], 'agents.*.share' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'referral_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $shares = [];
        foreach ($data['agents'] as $a) {
            $u = User::query()->where('ulid', $a['ulid'])->firstOrFail();
            $shares[] = new AgentShare($u->id, (string) $a['share']);
        }

        try {
            $result = $service->preview(new DealSideInput(Money::of((int) round((float) $data['gci'] * 100)), (int) round((float) $data['sale_price'] * 100),
                CarbonImmutable::parse($data['closing_date']), $shares, Side::from($data['side']), DealClass::from($data['deal_class']),
                outsideReferralPercent: isset($data['referral_percent']) ? (string) $data['referral_percent'] : null));
        } catch (InvalidRules $e) {
            return response()->json(['message' => implode(' ', $e->errors)], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'steps' => $result->explanation(),
            'agents' => array_map(fn ($a): array => ['user_id' => $a->userId, 'name' => User::query()->find($a->userId)?->name, 'plan' => $a->planName,
                'company_cents' => $a->companyDollar->cents, 'agent_net_cents' => $a->agentNet->cents, 'team_split_cents' => $a->teamSplit !== null ? $a->teamSplit->cents : 0, 'capped' => $a->capped], $result->agents),
        ]);
    }

    public function profitability(Request $request, Insights $insights): Response
    {
        $user = $this->can($request, 'compensation.plans.view');
        abort_unless($user->can('finance.amounts.view'), 403);
        $year = (int) $request->query('year', (string) now()->year);

        return Inertia::render('compensation/profitability', [
            'year' => $year,
            'report' => $insights->profitability(CarbonImmutable::parse("{$year}-01-01"), CarbonImmutable::parse("{$year}-12-31")),
        ]);
    }

    public function whatIf(Request $request, Insights $insights): JsonResponse
    {
        $user = $this->can($request, 'compensation.plans.view');
        abort_unless($user->can('finance.amounts.view'), 403);
        $data = $request->validate(['rules' => ['required', 'array']]);

        try {
            return response()->json($insights->whatIf($data['rules']));
        } catch (InvalidRules $e) {
            return response()->json(['message' => implode(' ', $e->errors)], 422);
        }
    }

    public function myPlan(Request $request, Insights $insights): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('compensation/my-plan', ['me' => $insights->myPlan($user)]);
    }

    private function currentPlanName(int $userId): ?string
    {
        $planId = PlanAssignment::query()->where('agent_user_id', $userId)->whereNull('effective_to')->orderByDesc('effective_from')->value('plan_id');

        return $planId !== null ? Plan::query()->whereKey($planId)->value('name') : null;
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidRules $e) {
            return back()->withErrors($e->errors);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private function can(Request $request, string $permission): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can($permission), 403);

        return $user;
    }
}
