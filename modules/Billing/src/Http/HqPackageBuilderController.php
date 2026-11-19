<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Builder\ModuleCatalog;
use Modules\Billing\Builder\PackageBuilder;
use Modules\Billing\Builder\PackageQuote;
use Modules\Billing\Models\Package;

/** HQ → Package builder (PB1): choose modules, see what they cost RightAlly, price at HQ's markup. */
final class HqPackageBuilderController
{
    public function index(PackageQuote $quotes): Response
    {
        $catalog = [];
        foreach (ModuleCatalog::MODULES as $key => $m) {
            $catalog[] = ['key' => $key, 'label' => $m['label'], 'about' => $m['about'], 'audience' => $m['audience'], 'always' => $m['always'] ?? false,
                'usage' => array_map(fn (array $u): array => ['key' => $u[0], 'label' => ModuleCatalog::usageLabel($u[0]), 'default' => $u[2], 'per' => $u[3]], $m['usage'])];
        }

        return Inertia::render('hq/packages', [
            'catalog' => $catalog,
            'rules' => PackageQuote::rules(),
            'packages' => Package::query()->orderBy('audience')->orderBy('monthly_cents')->get()->map(fn (Package $p): array => [
                'ulid' => $p->ulid, 'name' => $p->name, 'audience' => $p->getAttribute('audience') ?? 'brokerage', 'active' => $p->active, 'monthly_cents' => $p->monthly_cents,
                'modules' => $p->getAttribute('modules') ?? [], 'seats' => (int) ($p->getAttribute('seats') ?? 1), 'usage' => $p->getAttribute('usage') ?? (object) [],
                'markup_pct' => $p->getAttribute('markup_pct'), 'reason' => $p->getAttribute('price_reason'),
                // Today's cost (prices on the cost list may have changed since it was saved).
                'quote' => $quotes->quote($p->getAttribute('audience') ?? 'brokerage', $p->getAttribute('modules') ?? [], (int) ($p->getAttribute('seats') ?? 1), $p->getAttribute('usage') ?? [],
                    $p->getAttribute('markup_pct')),
            ])->values(),
            // "What if every module were on?" for each audience, at the default markup.
            'everything' => ['brokerage' => $quotes->quote('brokerage', array_keys(ModuleCatalog::MODULES), 10), 'agent' => $quotes->quote('agent', array_keys(ModuleCatalog::MODULES), 1)],
        ]);
    }

    /** Live price while HQ ticks modules (nothing is saved). */
    public function quote(Request $request, PackageQuote $quotes): JsonResponse
    {
        $d = $this->validated($request);

        return response()->json($quotes->quote($d['audience'], $d['modules'], $d['seats'], $d['usage'], $d['markup']));
    }

    public function save(Request $request, PackageBuilder $builder, ?Package $package = null): RedirectResponse
    {
        $d = $this->validated($request) + $request->validate(['name' => ['required', 'string', 'max:80'], 'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:300'], 'active' => ['nullable', 'boolean']]);
        $saved = $builder->save($package, ['name' => $d['name'], 'audience' => $d['audience'], 'modules' => $d['modules'], 'seats' => $d['seats'], 'usage' => $d['usage'],
            'markup' => $d['markup'], 'price_cents' => isset($d['price']) ? (int) round((float) $d['price'] * 100) : null, 'reason' => $d['reason'] ?? null,
            'active' => (bool) ($d['active'] ?? true)], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$saved->name} saved at $".number_format($saved->monthly_cents / 100).' a month.']);

        return redirect('/hq/packages');
    }

    public function rules(Request $request, PackageBuilder $builder): RedirectResponse
    {
        $d = $request->validate(['markup' => ['required', 'integer'], 'min_markup' => ['required', 'integer'], 'typical_use' => ['required', 'integer'], 'yearly_months' => ['required', 'integer']]);
        $builder->saveRules((int) $d['markup'], (int) $d['min_markup'], (int) $d['typical_use'], (int) $d['yearly_months'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pricing rules saved.']);

        return back();
    }

    /**
     * @return array{audience: string, modules: list<string>, seats: int, usage: array<string, array<string, int>>, markup: int|null}
     */
    private function validated(Request $request): array
    {
        $d = $request->validate(['audience' => ['required', 'in:brokerage,agent'], 'modules' => ['present', 'array', 'max:40'], 'modules.*' => ['string', 'max:40'],
            'seats' => ['required', 'integer', 'min:1', 'max:10000'], 'usage' => ['nullable', 'array'], 'usage.*' => ['array'], 'usage.*.*' => ['integer', 'min:0', 'max:10000000'],
            'markup' => ['nullable', 'integer', 'min:0', 'max:500']]);
        $usage = [];
        foreach ((array) ($d['usage'] ?? []) as $module => $items) {
            foreach ((array) $items as $key => $amount) {
                $usage[(string) $module][(string) $key] = (int) $amount;
            }
        }

        return ['audience' => (string) $d['audience'], 'modules' => array_values(array_map('strval', (array) $d['modules'])), 'seats' => (int) $d['seats'], 'usage' => $usage,
            'markup' => isset($d['markup']) ? (int) $d['markup'] : null];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
