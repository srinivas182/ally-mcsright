<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;
use Modules\Transactions\Models\Deal;

/**
 * Earnest money (specs/transactions/01): expected, received, released or refunded. Deposits held in the
 * brokerage's trust account post to the ledger (trust cash against escrow deposits held, per deal), so
 * the three-way trust reconciliation has every movement.
 */
final class EarnestMoney
{
    public const HELD_BY = ['trust', 'title', 'other'];

    public function expect(Deal $deal, int $amountCents, string $heldBy, ?string $heldByName, ?CarbonImmutable $dueOn, User $by): string
    {
        if ($amountCents <= 0 || ! in_array($heldBy, self::HELD_BY, true)) {
            throw ValidationException::withMessages(['deposit' => 'Give the amount and who holds it (the brokerage trust account, title, or other).']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('tx_deposits')->insert([
            'ulid' => $ulid, 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'amount_cents' => $amountCents, 'held_by' => $heldBy,
            'held_by_name' => $heldByName, 'due_on' => $dueOn?->toDateString(), 'status' => 'expected', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(AuditLogger::class)->record('transactions.deposit.expected', $deal, metadata: ['amount_cents' => $amountCents, 'held_by' => $heldBy], label: $deal->street);

        return $ulid;
    }

    public function receive(string $ulid, CarbonImmutable $on, string $method, ?string $reference, User $by): void
    {
        $d = $this->find($ulid, 'expected');
        $journal = null;
        if ($d['held_by'] === 'trust') {
            $accounts = $this->accounts();
            $journal = app(LedgerService::class)->post(JournalDraft::make('earnest_money', 'deposit:'.$ulid)->effectiveOn($on)->source('deposit', $ulid)
                ->describe('Earnest money received')
                ->debit($accounts->account('1010'), Money::of($d['amount_cents']))
                ->credit($accounts->subAccount('2300', 'deal', $d['deal_id'], 'Escrow deposit — deal '.$d['deal_id']), Money::of($d['amount_cents'])))->id;
        }
        DB::connection(Connections::cell())->table('tx_deposits')->where('ulid', $ulid)->update([
            'status' => 'received', 'received_on' => $on->toDateString(), 'method' => mb_substr($method, 0, 20), 'reference' => $reference !== null ? mb_substr($reference, 0, 60) : null,
            'journal_id' => $journal, 'updated_at' => now(),
        ]);
        app(AuditLogger::class)->record('transactions.deposit.received', metadata: ['deposit' => $ulid, 'amount_cents' => $d['amount_cents'], 'method' => $method], severity: 'notice');
    }

    /** Released to the closing (applied to the purchase) or refunded to the buyer. */
    public function release(string $ulid, bool $refund, string $to, CarbonImmutable $on, User $by): void
    {
        $d = $this->find($ulid, 'received');
        if (mb_strlen(trim($to)) < 3) {
            throw ValidationException::withMessages(['released_to' => 'Say who the money went to.']);
        }
        $journal = null;
        if ($d['held_by'] === 'trust') {
            $accounts = $this->accounts();
            $journal = app(LedgerService::class)->post(JournalDraft::make('earnest_money', 'deposit-release:'.$ulid)->effectiveOn($on)->source('deposit', $ulid)
                ->describe(($refund ? 'Earnest money refunded to ' : 'Earnest money released to ').$to)
                ->debit($accounts->subAccount('2300', 'deal', $d['deal_id'], 'Escrow deposit — deal '.$d['deal_id']), Money::of($d['amount_cents']))
                ->credit($accounts->account('1010'), Money::of($d['amount_cents'])))->id;
        }
        DB::connection(Connections::cell())->table('tx_deposits')->where('ulid', $ulid)->update([
            'status' => $refund ? 'refunded' : 'released', 'released_on' => $on->toDateString(), 'released_to' => mb_substr(trim($to), 0, 160),
            'release_journal_id' => $journal, 'updated_at' => now(),
        ]);
        app(AuditLogger::class)->record('transactions.deposit.'.($refund ? 'refunded' : 'released'), metadata: ['deposit' => $ulid, 'to' => $to], severity: 'warning');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forDeal(Deal $deal): array
    {
        $today = CarbonImmutable::today()->toDateString();

        return DB::connection(Connections::cell())->table('tx_deposits')->where('deal_id', $deal->id)->orderBy('id')->get()
            ->map(fn ($d): array => ['ulid' => $d->ulid, 'amount_cents' => (int) $d->amount_cents, 'held_by' => $d->held_by, 'held_by_name' => $d->held_by_name,
                'due_on' => $d->due_on, 'status' => $d->status, 'received_on' => $d->received_on, 'released_on' => $d->released_on, 'released_to' => $d->released_to,
                'overdue' => $d->status === 'expected' && $d->due_on !== null && (string) $d->due_on < $today])->values()->all();
    }

    /**
     * @return array{deal_id: int, amount_cents: int, held_by: string}
     */
    private function find(string $ulid, string $status): array
    {
        $d = DB::connection(Connections::cell())->table('tx_deposits')->where('ulid', $ulid)->first();
        if ($d === null || $d->status !== $status) {
            throw ValidationException::withMessages(['deposit' => 'This deposit is not '.$status.'.']);
        }

        return ['deal_id' => (int) $d->deal_id, 'amount_cents' => (int) $d->amount_cents, 'held_by' => (string) $d->held_by];
    }

    private function accounts(): ChartOfAccounts
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();

        return $accounts;
    }
}
