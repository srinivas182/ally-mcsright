<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Referrals;

/**
 * Money received from title (specs/finance/02, addition 1): recorded against the deal and checked
 * against what the CDA says the brokerage should receive. A match recognises the commission (receivable
 * against each agent's payable, the team lead's share and company dollar) and brings the cash in; a short
 * or over payment is recorded but the deal is held until someone resolves it.
 */
final class Receipts
{
    public function record(Deal $deal, int $amountCents, CarbonImmutable $on, string $method, ?string $reference, User $by): string
    {
        if (! in_array($method, ['wire', 'ach', 'check'], true) || $amountCents <= 0) {
            throw ValidationException::withMessages(['receipt' => 'Give the amount and how it arrived (wire, ACH or check).']);
        }
        $calc = $deal->calculation_id !== null ? Calculation::query()->find($deal->calculation_id) : null;
        if ($calc === null) {
            throw ValidationException::withMessages(['receipt' => 'This deal has no commission calculation yet.']);
        }
        $expected = $this->expected($deal, $calc);
        $already = (int) DB::connection(Connections::cell())->table('pay_receipts')->where('deal_id', $deal->id)->sum('amount_cents');
        $total = $already + $amountCents;
        $status = $total === $expected ? 'matched' : ($total < $expected ? 'short' : 'over');

        $ulid = DB::connection(Connections::cell())->transaction(function () use ($deal, $calc, $amountCents, $on, $method, $reference, $by, $expected, $status): string {
            $journal = $this->cashIn($deal, $amountCents, $on);
            $ulid = (string) Str::ulid();
            DB::connection(Connections::cell())->table('pay_receipts')->insert([
                'ulid' => $ulid, 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'amount_cents' => $amountCents, 'expected_cents' => $expected,
                'received_on' => $on->toDateString(), 'method' => $method, 'reference' => $reference !== null ? mb_substr($reference, 0, 60) : null,
                'status' => $status, 'recorded_by' => $by->id, 'journal_id' => $journal, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($status === 'matched') {
                $this->recognise($deal, $calc, $expected, $on);
            }
            app(AuditLogger::class)->record('payouts.receipt.recorded', $deal, metadata: ['amount_cents' => $amountCents, 'expected_cents' => $expected, 'status' => $status],
                severity: $status === 'matched' ? 'notice' : 'warning', label: $deal->street);

            return $ulid;
        });
        app(ComplianceScan::class)->run($deal); // money checks see the receipt; holds are current before paying
        if ($status === 'matched') {
            app(AutoPayouts::class)->afterSettled($deal, $by);
        }

        return $ulid;
    }

    /** A broker accepts a short or over payment as final; the brokerage absorbs the difference. */
    public function accept(string $ulid, string $note, User $by): void
    {
        if (mb_strlen(trim($note)) < 10) {
            throw ValidationException::withMessages(['note' => 'Say why the different amount is accepted (at least 10 characters).']);
        }
        $db = DB::connection(Connections::cell());
        $receipt = $db->table('pay_receipts')->where('ulid', $ulid)->whereIn('status', ['short', 'over'])->first();
        if ($receipt === null) {
            throw ValidationException::withMessages(['receipt' => 'This payment does not need resolving.']);
        }
        $deal = Deal::query()->findOrFail((int) $receipt->deal_id);
        $calc = Calculation::query()->findOrFail((int) $deal->calculation_id);
        $received = (int) $db->table('pay_receipts')->where('deal_id', $deal->id)->sum('amount_cents');
        $db->transaction(function () use ($db, $receipt, $deal, $calc, $received, $note, $by): void {
            // Agents are paid what the CDA says; company dollar takes the difference.
            $this->recognise($deal, $calc, (int) $receipt->expected_cents, CarbonImmutable::now(), $received);
            $db->table('pay_receipts')->where('deal_id', $deal->id)->whereIn('status', ['short', 'over'])
                ->update(['status' => 'accepted', 'resolution' => mb_substr(trim($note), 0, 300), 'resolved_by' => $by->id, 'updated_at' => now()]);
        });
        app(AuditLogger::class)->record('payouts.receipt.accepted', $deal, metadata: ['received_cents' => $received, 'expected_cents' => (int) $receipt->expected_cents, 'note' => $note],
            severity: 'warning', label: $deal->street);
        app(ComplianceScan::class)->run($deal);
        app(AutoPayouts::class)->afterSettled($deal, $by);
    }

    /** Whether the deal's money has arrived (matched or accepted), so its commission can be paid. */
    public function settled(int $dealId): bool
    {
        return DB::connection(Connections::cell())->table('pay_recognitions')->where('deal_id', $dealId)->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forDeal(Deal $deal): array
    {
        return DB::connection(Connections::cell())->table('pay_receipts')->where('deal_id', $deal->id)->orderBy('id')->get()
            ->map(fn ($r): array => ['ulid' => $r->ulid, 'amount_cents' => (int) $r->amount_cents, 'expected_cents' => (int) $r->expected_cents,
                'received_on' => $r->received_on, 'method' => $r->method, 'reference' => $r->reference, 'status' => $r->status, 'resolution' => $r->resolution])->values()->all();
    }

    /** What the brokerage should receive: the CDA's brokerage line, or GCI minus the outside referral. */
    public function expected(Deal $deal, Calculation $calc): int
    {
        $cda = DB::connection(Connections::cell())->table('tx_cdas')->where('deal_id', $deal->id)->whereIn('status', ['approved', 'sent', 'acknowledged'])->orderByDesc('version')->value('payload');
        if ($cda !== null) {
            $payees = (array) (json_decode((string) $cda, true)['payees'] ?? []);

            return (int) (end($payees)['amount_cents'] ?? 0);
        }

        $outside = (int) ($calc->result['outside_referral_cents'] ?? 0);
        $shares = app(Referrals::class)->amounts($deal, $outside);
        $titlePaid = $shares === [] ? $outside : array_sum(array_column(array_filter($shares, fn ($r) => $r['paid_by'] === 'title'), 'cents'));

        return (int) ($calc->result['gci_cents'] ?? 0) - $titlePaid;
    }

    private function cashIn(Deal $deal, int $cents, CarbonImmutable $on): int
    {
        $accounts = $this->accounts();

        return app(LedgerService::class)->post(JournalDraft::make('commission_received', 'receipt:'.$deal->ulid.':'.Str::ulid())->effectiveOn($on)->source('deal', $deal->ulid)
            ->describe('Commission received for '.$deal->street)
            ->debit($accounts->account('1000'), Money::of($cents))
            ->credit($accounts->subAccount('1100', 'deal', $deal->id, 'Commission receivable — '.$deal->street), Money::of($cents)))->id;
    }

    /**
     * Recognises the commission once: receivable (what the brokerage should receive) against each agent's
     * payable (net), the team lead's share, and company dollar (the rest). With $received, the difference
     * between received and expected is absorbed by company dollar.
     */
    private function recognise(Deal $deal, Calculation $calc, int $expected, CarbonImmutable $on, ?int $received = null): void
    {
        if ($this->settled($deal->id)) {
            return;
        }
        $accounts = $this->accounts();
        $payables = [];
        foreach ((array) ($calc->result['agents'] ?? []) as $row) {
            $payables[(int) $row['user_id']] = ($payables[(int) $row['user_id']] ?? 0) + (int) $row['agent_net_cents'];
            if ((int) ($row['team_split_cents'] ?? 0) > 0 && isset($row['team_leader_user_id'])) {
                $payables[(int) $row['team_leader_user_id']] = ($payables[(int) $row['team_leader_user_id']] ?? 0) + (int) $row['team_split_cents'];
            }
        }
        // Agents title paid directly (CDA option) have no payable here; they are recorded for 1099s.
        $cda = DB::connection(Connections::cell())->table('tx_cdas')->where('deal_id', $deal->id)->whereIn('status', ['approved', 'sent', 'acknowledged'])->orderByDesc('version')->value('payload');
        $paidByTitle = array_map('intval', (array) (json_decode((string) ($cda ?? '{}'), true)['agents_paid_by_title'] ?? []));
        foreach (array_keys($paidByTitle) as $userId) {
            unset($payables[(int) $userId]);
        }
        $basis = $received ?? $expected;
        // Referral fees the brokerage pays itself become referral payables (paid in a payout run).
        $referrals = array_filter(app(Referrals::class)->amounts($deal, (int) ($calc->result['outside_referral_cents'] ?? 0)), fn ($r) => $r['paid_by'] === 'brokerage');
        $company = $basis - array_sum($payables) - array_sum(array_column($referrals, 'cents'));
        $draft = JournalDraft::make('commission_recognised', 'recognise:'.$deal->ulid)->effectiveOn($on)->source('deal', $deal->ulid)
            ->describe('Commission recognised for '.$deal->street)
            ->debit($accounts->subAccount('1100', 'deal', $deal->id, 'Commission receivable — '.$deal->street), Money::of($basis));
        foreach ($payables as $userId => $cents) {
            if ($cents > 0) {
                $name = User::query()->whereKey($userId)->value('first_name') ?? 'Agent';
                $draft->credit($accounts->subAccount('2000', 'user', $userId, "Commission payable — {$name}"), Money::of($cents));
            }
        }
        foreach ($referrals as $r) {
            $draft->credit($accounts->subAccount('2200', 'referral', $r['id'], 'Referral payable — '.$r['brokerage']), Money::of($r['cents']));
        }
        $company >= 0 ? $draft->credit($accounts->account('4000'), Money::of($company)) : $draft->debit($accounts->account('4000'), Money::of(-$company));
        $journal = app(LedgerService::class)->post($draft)->id;
        DB::connection(Connections::cell())->table('pay_recognitions')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'journal_id' => $journal,
            'payables' => json_encode($payables), 'paid_by_title' => $paidByTitle !== [] ? json_encode($paidByTitle) : null, 'created_at' => now()]);
    }

    private function accounts(): ChartOfAccounts
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();

        return $accounts;
    }
}
