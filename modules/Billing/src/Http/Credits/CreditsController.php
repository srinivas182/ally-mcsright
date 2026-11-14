<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Credits;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Credits\AutoTopUp;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\CreditPurchases;
use Modules\Billing\Credits\Models\CreditLedgerEntry;
use Modules\Billing\Credits\RateCard;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Credits (specs/billing/01): the brokerage's balance and this month's use by person, automation and
 * feature; giving credits to agents and the pool rules (owners and staff). Agents see their own credits.
 */
final class CreditsController
{
    public function __construct(private readonly CreditLedger $ledger, private readonly RateCard $rates, private readonly CreditPurchases $purchases) {}

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
            'pool' => $this->ledger->poolSummary(), 'policy' => $this->ledger->policy(),
            'packs' => $this->purchases->packsFor($user), 'purchases' => $this->purchases->list($user),
            'checkout' => $request->query('checkout') === 'success' ? 'success' : ($request->query('checkout') === 'cancelled' ? 'cancelled' : null),
            'value_microcents' => $this->rates->creditValueMicrocents(),
            'autopay' => app(AutoTopUp::class)->settings($manager ? null : $user->id),
            'map_price' => ['credits' => $this->rates->rate('usage.map_views_10')['credits'], 'per' => 10]];
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
        $data = $request->validate(['agents_use_pool' => ['required', 'boolean'], 'agent_monthly_cap' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'agents_can_buy' => ['sometimes', 'boolean']]);
        $this->ledger->savePolicy((bool) $data['agents_use_pool'], isset($data['agent_monthly_cap']) ? (int) $data['agent_monthly_cap'] : null, $user,
            isset($data['agents_can_buy']) ? (bool) $data['agents_can_buy'] : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    /** One click: buys a pack on the saved card. */
    public function buySaved(Request $request): RedirectResponse
    {
        $data = $request->validate(['pack' => ['required', 'string', 'size:26']]);
        $status = $this->purchases->buyWithSavedCard(self::user($request), $data['pack']);
        Inertia::flash('toast', match ($status) {
            'succeeded' => ['type' => 'success', 'message' => 'Paid. The credits are in your balance.'],
            'processing' => ['type' => 'success', 'message' => 'Payment is processing. The credits arrive as soon as it clears.'],
            default => ['type' => 'error', 'message' => 'Your card was not charged. Use "Buy" to pay with another card.'],
        });

        return back();
    }

    /** Automatic top-up: on or off, the balance that triggers it, and which pack to buy. */
    public function autopay(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'threshold' => ['required', 'integer', 'min:0', 'max:10000000'], 'pack' => ['nullable', 'string', 'size:26']]);
        if ($data['pack'] !== null && ! collect($this->purchases->packsFor($user))->contains('ulid', $data['pack'])) {
            throw ValidationException::withMessages(['pack' => 'Choose one of the credit packs on this page.']);
        }
        app(AutoTopUp::class)->save(self::isManager($user) ? null : $user->id, (bool) $data['enabled'], (int) $data['threshold'], $data['pack'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['enabled'] ? 'Automatic top-up is on.' : 'Automatic top-up is off.']);

        return back();
    }

    public function removeCard(Request $request): RedirectResponse
    {
        $user = self::user($request);
        app(AutoTopUp::class)->removeCard(self::isManager($user) ? null : $user->id, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved card removed. Automatic top-up is off.']);

        return back();
    }

    /** Sends the buyer to the payment page for a credit pack. */
    public function buy(Request $request): SymfonyResponse
    {
        $data = $request->validate(['pack' => ['required', 'string', 'size:26']]);

        return Inertia::location($this->purchases->start(self::user($request), $data['pack'], $request->getSchemeAndHttpHost()));
    }

    public function receipt(Request $request, string $purchase): Response
    {
        return Inertia::render('settings/credit-receipt', ['receipt' => $this->purchases->receipt(self::user($request), $purchase)]);
    }

    /** This month's (or a chosen month's) credit history as CSV: the brokerage's for owners and staff, your own for agents. */
    public function export(Request $request): StreamedResponse
    {
        $user = self::user($request);
        $month = (string) ($request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['month'] ?? CreditLedger::period());
        $manager = self::isManager($user);
        $rates = $this->rates->all();
        $rows = CreditLedgerEntry::query()->where('period', $month)->when(! $manager, fn ($q) => $q->where('user_id', $user->id))->orderBy('id')->limit(100_000)->get();
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->get()->keyBy('id');
        $label = fn (?string $a): string => $a !== null ? ($rates[$a]['label'] ?? $a) : '';
        $name = 'rightally-credits-'.$month.($manager ? '' : '-mine').'.csv';
        app(AuditLogger::class)->record('billing.credits.exported', metadata: ['month' => $month, 'rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows, $names, $label): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Date (UTC)', 'Type', 'Credits', 'What', 'Person', 'Automatic', 'Note'], escape: '');
            foreach ($rows as $e) {
                fputcsv($out, [$e->created_at->format('Y-m-d H:i:s'), $e->entry_type, $e->amount, self::cell($label($e->action_key)),
                    self::cell($e->user_id !== null ? ($names->get($e->user_id)->name ?? 'Former user') : 'Brokerage'), $e->automation ? 'yes' : 'no', self::cell((string) $e->note)], escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Stops spreadsheet formula injection (a cell starting with =, +, - or @ is shown as text). */
    private static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
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
