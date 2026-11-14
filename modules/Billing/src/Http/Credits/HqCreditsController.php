<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Credits;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\Economics;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Models\Package;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * HQ → RightAlly credits (specs/billing/01, 02): what a credit sells for, HQ's margin rules, what each action really
 * costs (model, tokens, real and planning cost, margin, suggested credits), the costs credits don't cover (texts,
 * calls, maps, email, hosting), each package's margin for a typical customer and in the worst case, and the switch
 * that makes running out of credits stop AI.
 */
#[CrossTenantAccess('HQ sees AI usage and cost across brokerages to price credits, and grants credits to a brokerage')]
final class HqCreditsController
{
    public function __construct(private readonly RateCard $rates, private readonly Economics $economics) {}

    public function index(): Response
    {
        $period = CreditLedger::period();
        $used = DB::connection(Connections::cell())->table('credit_ledger')->where('period', $period)->whereIn('entry_type', ['consume', 'refund'])
            ->selectRaw('tenant_id, -sum(amount) as credits')->groupBy('tenant_id')->orderByDesc('credits')->limit(50)->get();
        $tenants = Tenant::query()->whereIn('id', $used->pluck('tenant_id'))->pluck('name', 'id');
        $packages = Package::query()->orderBy('monthly_cents')->get();

        return Inertia::render('hq/credits', [
            'credit_value_microcents' => $this->rates->creditValueMicrocents(),
            'enforced' => $this->rates->enforced(),
            'margins' => $this->rates->margins(),
            'actions' => array_values($this->economics->actions()),
            'typical_cost_per_credit' => $this->economics->typicalCostPerCredit(),
            'worst_cost_per_credit' => $this->economics->worstCostPerCredit(),
            'catalog' => DB::connection(Connections::control())->table('cost_catalog')->orderBy('kind')->orderBy('id')->get()
                ->map(fn (object $c): array => ['key' => (string) $c->key, 'label' => (string) $c->label, 'unit' => (string) $c->unit, 'kind' => (string) $c->kind,
                    'cost_microcents' => (int) $c->cost_microcents, 'free_units_monthly' => (int) $c->free_units_monthly, 'note' => $c->note !== null ? (string) $c->note : null,
                    'source_url' => $c->source_url !== null ? (string) $c->source_url : null, 'checked_at' => $c->checked_at !== null ? (string) $c->checked_at : null])->values(),
            'packages' => $packages->map(fn (Package $p): array => ['id' => $p->ulid, 'name' => $p->name, 'monthly_cents' => $p->monthly_cents, 'active' => (bool) $p->active,
                'margin_override_reason' => $p->getAttribute('margin_override_reason')] + $this->economics->packagePlan($p))->values(),
            'top_usage' => $used->map(fn (object $r): array => ['tenant' => $tenants[$r->tenant_id] ?? 'Unknown', 'credits' => (int) $r->credits])->values(),
            'tenants' => Tenant::query()->where('type', '!=', 'hq')->orderBy('name')->get(['ulid', 'name'])->map(fn (Tenant $t): array => ['ulid' => $t->ulid, 'name' => $t->name])->values(),
            'period' => $period,
        ]);
    }

    public function enforce(Request $request): RedirectResponse
    {
        $on = $request->boolean('enforce');
        if ($on && ($missing = $this->packagesWithoutCredits()) !== []) {
            throw ValidationException::withMessages(['enforce' => 'Give these active packages included credits first: '.implode(', ', $missing).'.']);
        }
        $this->rates->saveEnforced($on, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $on ? 'Credits are now enforced.' : 'Credits are recorded but not enforced.']);

        return back();
    }

    /**
     * One step to go live: gives every active package its suggested included credits (where it has none, or all of
     * them when asked), then turns enforcement on.
     */
    public function goLive(Request $request): RedirectResponse
    {
        $by = self::user($request);
        $all = $request->boolean('replace_existing');
        $changed = [];
        foreach (Package::query()->where('active', true)->get() as $package) {
            $current = (int) $package->getAttribute('included_credits');
            if ($current > 0 && ! $all) {
                continue;
            }
            $suggested = $this->economics->suggestedIncludedCredits($package);
            if ($suggested > 0 && $suggested !== $current) {
                $package->forceFill(['included_credits' => $suggested])->save();
                $changed[$package->name] = ['old' => $current, 'new' => $suggested];
            }
        }
        if (($missing = $this->packagesWithoutCredits()) !== []) {
            throw ValidationException::withMessages(['enforce' => 'No suggestion for '.implode(', ', $missing).': add AI model prices (HQ → AI gateway) or set credits by hand.']);
        }
        app(AuditLogger::class)->record('billing.credits.go_live', metadata: ['included_credits' => $changed], severity: 'warning');
        $this->rates->saveEnforced(true, $by);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Included credits set'.($changed === [] ? '' : ' for '.count($changed).' package(s)').' and credits switched on.']);

        return back();
    }

    public function value(Request $request): RedirectResponse
    {
        // Dollars per credit, e.g. 0.01.
        $dollars = (float) $request->validate(['dollars' => ['required', 'numeric', 'min:0.00001', 'max:10']])['dollars'];
        $this->rates->saveCreditValue((int) round($dollars * 100 * 1_000_000), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Credit value saved.']);

        return back();
    }

    public function margins(Request $request): RedirectResponse
    {
        $data = $request->validate(['target' => ['required', 'integer', 'min:0', 'max:95'], 'floor' => ['required', 'integer', 'min:0', 'max:95'],
            'usage_budget' => ['required', 'integer', 'min:1', 'max:90']]);
        $this->rates->saveMargins((int) $data['target'], (int) $data['floor'], (int) $data['usage_budget'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Margin rules saved.']);

        return back();
    }

    public function rate(Request $request): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'string', 'max:80'], 'credits' => ['required', 'integer', 'min:0', 'max:100000'], 'automation' => ['required', 'boolean'],
            'label' => ['nullable', 'string', 'max:120'],
            'tokens_in' => ['nullable', 'integer', 'min:0', 'max:2000000'], 'tokens_out' => ['nullable', 'integer', 'min:0', 'max:500000'],
            'audio_seconds' => ['nullable', 'integer', 'min:0', 'max:3600']]);
        $by = self::user($request);
        $this->rates->saveRate($data['action'], (int) $data['credits'], (bool) $data['automation'], (string) ($data['label'] ?? ''), $by);
        if (isset($data['tokens_in'])) {
            $this->rates->saveEstimate($data['action'], (int) $data['tokens_in'], (int) ($data['tokens_out'] ?? 0), (int) ($data['audio_seconds'] ?? 0), $by);
        }

        return back();
    }

    /** Sets every action (or only those below the minimum margin) to its suggested credits. */
    public function applySuggested(Request $request): RedirectResponse
    {
        $by = self::user($request);
        $onlyBelow = $request->boolean('only_below_floor');
        $n = 0;
        foreach ($this->economics->actions() as $a) {
            if ($a['suggested_credits'] === null || $a['suggested_credits'] === $a['credits'] || ($onlyBelow && ! $a['below_floor'])) {
                continue;
            }
            $this->rates->saveRate($a['action'], $a['suggested_credits'], $a['automation'], $a['label'], $by);
            $n++;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $n === 0 ? 'Every price already matches.' : "Updated {$n} price(s)."]);

        return back();
    }

    public function catalog(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate(['cost' => ['required', 'numeric', 'min:0', 'max:10000'], 'free_units_monthly' => ['nullable', 'integer', 'min:0', 'max:100000000']]);
        $db = DB::connection(Connections::control())->table('cost_catalog');
        $row = (clone $db)->where('key', $key)->first() ?? abort(404);
        $micro = (int) round((float) $data['cost'] * 100_000_000);
        (clone $db)->where('key', $key)->update(['cost_microcents' => $micro, 'free_units_monthly' => (int) ($data['free_units_monthly'] ?? $row->free_units_monthly),
            'checked_at' => CarbonImmutable::today()->toDateString(), 'updated_by' => self::user($request)->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.costs.changed', changes: ['cost_microcents' => ['old' => (int) $row->cost_microcents, 'new' => $micro]], metadata: ['item' => $key], severity: 'notice');

        return back();
    }

    /** Sets a package's included credits. Below the minimum margin for a typical customer it needs a reason. */
    public function packageCredits(Request $request, Package $package): RedirectResponse
    {
        $data = $request->validate(['included_credits' => ['required', 'integer', 'min:0', 'max:10000000'], 'reason' => ['nullable', 'string', 'max:255']]);
        $credits = (int) $data['included_credits'];
        $plan = $this->economics->packagePlan($package, $credits);
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($plan['below_floor'] && mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'At '.number_format($credits).' credits this package\'s margin is '.$plan['typical_margin_pct'].'%, below your minimum of '
                .$this->rates->margins()['floor'].'%. Lower the credits, or give a reason (at least 10 characters).']);
        }
        $old = (int) $package->getAttribute('included_credits');
        $package->forceFill(['included_credits' => $credits, 'margin_override_reason' => $plan['below_floor'] ? $reason : null])->save();
        app(AuditLogger::class)->record('billing.package.credits', changes: ['included_credits' => ['old' => $old, 'new' => $credits]],
            metadata: ['package' => $package->name, 'margin_pct' => $plan['typical_margin_pct'], 'reason' => $plan['below_floor'] ? $reason : null], severity: $plan['below_floor'] ? 'warning' : 'notice');

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

    /** @return list<string> */
    private function packagesWithoutCredits(): array
    {
        return array_values(Package::query()->where('active', true)->where('monthly_cents', '>', 0)->where('included_credits', '<=', 0)->pluck('name')
            ->map(fn ($n): string => (string) $n)->all());
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
