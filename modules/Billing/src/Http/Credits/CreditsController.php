<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Credits;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\Models\CreditLedgerEntry;
use Modules\Billing\Credits\RateCard;
use Modules\Core\Identity\Enums\UserKind;

/**
 * Settings → Credits (specs/billing/01): the brokerage's balance and this month's use by person, automation and
 * feature; giving credits to agents and the pool rules (owners and staff). Agents see their own credits.
 */
final class CreditsController
{
    public function __construct(private readonly CreditLedger $ledger, private readonly RateCard $rates) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $manager = self::isManager($user);
        $rates = $this->rates->all();
        $label = fn (?string $action): string => $action !== null ? ($rates[$action]['label'] ?? $action) : '';
        $entries = fn (?int $userId): array => array_map(fn (CreditLedgerEntry $e): array => ['at' => $e->created_at->toIso8601String(), 'type' => $e->entry_type, 'amount' => $e->amount,
            'what' => $label($e->action_key) ?: (string) $e->note, 'automation' => $e->automation], $this->ledger->history($userId, 40));

        $props = ['manager' => $manager, 'me' => $this->ledger->userSummary($user->id), 'my_history' => $entries($user->id),
            'prices' => array_map(fn (string $k, array $r): array => ['action' => $k, 'label' => $r['label'], 'credits' => $r['credits'], 'automation' => $r['automation']],
                array_keys($rates), $rates),
            'pool' => $this->ledger->poolSummary(), 'policy' => $this->ledger->policy()];
        if ($manager) {
            $usage = $this->ledger->usage();
            $names = User::query()->whereIn('id', array_column($usage['people'], 'user_id'))->get()->keyBy('id');
            $people = User::query()->whereIn('kind', [UserKind::Agent, UserKind::Staff, UserKind::Owner, UserKind::Assistant])->orderBy('first_name')->limit(500)->get()
                ->filter(fn (User $u): bool => $u->isActive());
            $props += [
                'usage' => ['people' => array_map(fn (array $p): array => ['name' => $names->get($p['user_id'])->name ?? 'Former user', 'credits' => $p['credits']], $usage['people']),
                    'automations' => $usage['automations'],
                    'features' => array_map(fn (array $f): array => $f + ['label' => $label($f['action'])], $usage['features'])],
                'agents' => $people->filter(fn (User $u): bool => $u->kind === UserKind::Agent)->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name] + $this->ledger->userSummary($u->id))->values()->all(),
                'pool_history' => $entries(null),
            ];
        }

        return Inertia::render('settings/credits', $props);
    }

    /** The consent prompt's numbers: what an action costs and what this person has. */
    public function quote(Request $request): JsonResponse
    {
        $action = (string) $request->validate(['action' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/']])['action'];

        return response()->json($this->ledger->quote($action, self::user($request)))->header('Cache-Control', 'no-store');
    }

    public function allot(Request $request): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'credits' => ['required', 'integer', 'between:-1000000,1000000', 'not_in:0']]);
        $agent = User::query()->whereKey($data['user_id'])->where('kind', UserKind::Agent)->firstOrFail();
        $this->ledger->allot($agent, (int) $data['credits'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => ($data['credits'] > 0 ? 'Gave ' : 'Took back ').number_format(abs((int) $data['credits'])).' credits '.($data['credits'] > 0 ? 'to ' : 'from ').$agent->first_name.'.']);

        return back();
    }

    public function policy(Request $request): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['agents_use_pool' => ['required', 'boolean'], 'agent_monthly_cap' => ['nullable', 'integer', 'min:0', 'max:1000000']]);
        $this->ledger->savePolicy((bool) $data['agents_use_pool'], isset($data['agent_monthly_cap']) ? (int) $data['agent_monthly_cap'] : null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    private static function isManager(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
    }

    private static function manager(Request $request): User
    {
        $user = self::user($request);
        abort_unless(self::isManager($user), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
