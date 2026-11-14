<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Credits;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Models\Package;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * HQ → Credits (specs/billing/01): what a credit is worth, the price of every action, and the economics behind them —
 * the AI model each action uses, its token prices, the real average tokens and cost over the last 30 days, revenue
 * per use, margin, and how many tokens one credit buys. Also the credits included in each package, and manual grants.
 */
#[CrossTenantAccess('HQ sees average AI usage and cost across brokerages to price credits, and grants credits to a brokerage')]
final class HqCreditsController
{
    public function __construct(private readonly RateCard $rates) {}

    public function index(): Response
    {
        $value = $this->rates->creditValueMicrocents();
        $routes = AiFeatureRoute::query()->get()->keyBy('credit_action_key');
        $models = AiModel::query()->with('provider')->get()->keyBy('id');
        $since = CarbonImmutable::now()->subDays(30);
        $stats = DB::connection(Connections::cell())->table('ai_requests')->where('created_at', '>=', $since)
            ->selectRaw("feature_key, sum(case when status = 'ok' then 1 else 0 end) as ok, sum(tokens_in) as tin, sum(tokens_out) as tout, sum(cost_microcents) as cost")
            ->groupBy('feature_key')->get()->keyBy('feature_key');

        $actions = [];
        foreach ($this->rates->all() as $key => $rate) {
            $route = $routes->get($key);
            $model = $route !== null ? $models->get($route->primary_model_id) : null;
            $s = $route !== null ? $stats->get($route->feature_key) : null;
            $uses = (int) ($s->ok ?? 0);
            // All attempts' cost (retries, blocked output) spread over the successful uses.
            $avgCost = $uses > 0 ? (int) round((int) ($s->cost ?? 0) / $uses) : null;
            $avgIn = $uses > 0 ? (int) round((int) ($s->tin ?? 0) / $uses) : null;
            $avgOut = $uses > 0 ? (int) round((int) ($s->tout ?? 0) / $uses) : null;
            $revenue = $rate['credits'] * $value;
            // Tokens one credit buys on this model, at this action's real in/out mix (3:1 when there is no data yet).
            $tokensPerCredit = null;
            if ($model !== null) {
                $in = $avgIn ?? 3;
                $out = $avgOut ?? 1;
                $costPerToken = ($in * $model->input_cost_per_mtok_cents + $out * $model->output_cost_per_mtok_cents) / max(1, $in + $out); // cents per million tokens
                $tokensPerCredit = $costPerToken > 0 ? (int) floor($value / $costPerToken) : null; // value is in millionths of a cent
            }
            $actions[] = ['action' => $key, 'label' => $rate['label'], 'credits' => $rate['credits'], 'automation' => $rate['automation'],
                'feature' => $route?->feature_key, 'model' => $model !== null ? ['name' => $model->display_name, 'provider' => $model->provider->name ?? '',
                    'input_per_mtok_cents' => $model->input_cost_per_mtok_cents, 'output_per_mtok_cents' => $model->output_cost_per_mtok_cents] : null,
                'uses_30d' => $uses, 'avg_tokens_in' => $avgIn, 'avg_tokens_out' => $avgOut, 'avg_cost_microcents' => $avgCost, 'revenue_microcents' => $revenue,
                'margin_pct' => $avgCost !== null && $revenue > 0 ? (int) round(($revenue - $avgCost) * 100 / $revenue) : null, 'tokens_per_credit' => $tokensPerCredit];
        }

        $period = CreditLedger::period();
        $used = DB::connection(Connections::cell())->table('credit_ledger')->where('period', $period)->whereIn('entry_type', ['consume', 'refund'])
            ->selectRaw('tenant_id, -sum(amount) as credits')->groupBy('tenant_id')->orderByDesc('credits')->limit(50)->get();
        $tenants = Tenant::query()->whereIn('id', $used->pluck('tenant_id'))->pluck('name', 'id');

        return Inertia::render('hq/credits', [
            'credit_value_microcents' => $value,
            'enforced' => $this->rates->enforced(),
            'actions' => $actions,
            'packages' => Package::query()->orderBy('monthly_cents')->get()->map(fn (Package $p): array => ['id' => $p->ulid, 'name' => $p->name, 'monthly_cents' => $p->monthly_cents,
                'included_credits' => (int) $p->getAttribute('included_credits')])->values(),
            'top_usage' => $used->map(fn ($r): array => ['tenant' => $tenants[$r->tenant_id] ?? 'Unknown', 'credits' => (int) $r->credits])->values(),
            'tenants' => Tenant::query()->where('type', '!=', 'hq')->orderBy('name')->get(['ulid', 'name'])->map(fn (Tenant $t): array => ['ulid' => $t->ulid, 'name' => $t->name])->values(),
            'period' => $period,
        ]);
    }

    public function value(Request $request): RedirectResponse
    {
        // Dollars per credit, e.g. 0.01.
        $dollars = (float) $request->validate(['dollars' => ['required', 'numeric', 'min:0.00001', 'max:10']])['dollars'];
        $this->rates->saveCreditValue((int) round($dollars * 100 * 1_000_000), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Credit value saved.']);

        return back();
    }

    public function enforce(Request $request): RedirectResponse
    {
        $this->rates->saveEnforced($request->boolean('enforce'), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('enforce') ? 'Credits are now enforced.' : 'Credits are recorded but not enforced.']);

        return back();
    }

    public function rate(Request $request): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'string', 'max:80'], 'credits' => ['required', 'integer', 'min:0', 'max:100000'], 'automation' => ['required', 'boolean'],
            'label' => ['nullable', 'string', 'max:120']]);
        $this->rates->saveRate($data['action'], (int) $data['credits'], (bool) $data['automation'], (string) ($data['label'] ?? ''), self::user($request));

        return back();
    }

    public function packageCredits(Request $request, Package $package): RedirectResponse
    {
        $credits = (int) $request->validate(['included_credits' => ['required', 'integer', 'min:0', 'max:10000000']])['included_credits'];
        $old = (int) $package->getAttribute('included_credits');
        $package->forceFill(['included_credits' => $credits])->save();
        app(AuditLogger::class)->record('billing.package.credits', changes: ['included_credits' => ['old' => $old, 'new' => $credits]], metadata: ['package' => $package->name], severity: 'notice');

        return back();
    }

    /** Adds (or removes) credits for a brokerage, with a reason (goodwill, corrections). */
    public function grant(Request $request, TenantContext $tenants): RedirectResponse
    {
        $by = self::user($request);
        $data = $request->validate(['tenant' => ['required', 'string', 'size:26'], 'credits' => ['required', 'integer', 'between:-1000000,1000000', 'not_in:0'], 'note' => ['required', 'string', 'max:200']]);
        $tenant = Tenant::query()->where('ulid', $data['tenant'])->where('type', '!=', 'hq')->firstOrFail();
        $tenants->runAs($tenant, fn () => app(CreditLedger::class)->add(null, (int) $data['credits'], 'adjust', 'RightAlly: '.$data['note'], $by));
        Inertia::flash('toast', ['type' => 'success', 'message' => number_format((int) $data['credits']).' credits applied to '.$tenant->name.'.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
