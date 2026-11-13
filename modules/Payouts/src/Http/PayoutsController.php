<?php

declare(strict_types=1);

namespace Modules\Payouts\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Compensation\Models\Calculation;
use Modules\Payouts\Balances;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\Receipts;
use Modules\Transactions\Models\Deal;

final class PayoutsController
{
    /** Finance: deals awaiting money from title, and what arrived. */
    public function receipts(Request $request, Receipts $receipts): Response
    {
        $this->finance($request);
        $only = (string) $request->query('show', 'open');
        $deals = Deal::query()->whereIn('status', ['under_contract', 'closed'])->whereNotNull('calculation_id')->orderBy('closing_date')->limit(300)->get();

        return Inertia::render('finance/receipts', [
            'show' => $only,
            'deals' => $deals->map(function (Deal $d) use ($receipts): array {
                $calc = Calculation::query()->find($d->calculation_id);
                $rows = $receipts->forDeal($d);

                return ['ulid' => $d->ulid, 'street' => $d->street, 'status' => $d->status, 'closing_date' => $d->closing_date?->toDateString(),
                    'expected_cents' => $calc !== null ? $receipts->expected($d, $calc) : 0, 'received_cents' => array_sum(array_column($rows, 'amount_cents')),
                    'settled' => $receipts->settled($d->id), 'receipts' => $rows];
            })->filter(fn (array $d): bool => match ($only) {
                'all' => true,
                'problems' => collect($d['receipts'])->contains(fn ($r) => in_array($r['status'], ['short', 'over'], true)),
                default => ! $d['settled'],
            })->values(),
        ]);
    }

    public function record(Request $request, Deal $deal, Receipts $receipts): RedirectResponse
    {
        $by = $this->finance($request);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'received_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:wire,ach,check'], 'reference' => ['nullable', 'string', 'max:60']]);
        $receipts->record($deal, (int) round((float) $data['amount'] * 100), CarbonImmutable::parse($data['received_on']), $data['method'], $data['reference'] ?? null, $by);

        return back();
    }

    public function accept(Request $request, string $receipt, Receipts $receipts): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']]);
        $receipts->accept($receipt, $data['note'], $this->finance($request));

        return back();
    }

    /** Agent: balances and payout bank account. */
    public function mine(Request $request, Balances $balances, BankAccounts $accounts): Response
    {
        $user = self::user($request);

        return Inertia::render('finance/my-money', ['balances' => $balances->for($user), 'bank' => $accounts->current($user), 'holdHours' => BankAccounts::HOLD_HOURS]);
    }

    public function setBank(Request $request, BankAccounts $accounts): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['holder' => ['required', 'string', 'max:120'], 'routing' => ['required', 'string', 'max:20'], 'account' => ['required', 'string', 'max:30'],
            'type' => ['required', 'in:checking,savings']]);
        $accounts->set($user, $data['holder'], $data['routing'], $data['account'], $data['type'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. For your protection, payouts go to the new account after '.BankAccounts::HOLD_HOURS.' hours.']);

        return back();
    }

    private function finance(Request $request): User
    {
        $user = self::user($request);
        abort_unless($user->can('finance.receipts.record'), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
