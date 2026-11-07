<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Enums\NormalSide;
use Modules\Ledger\Enums\PeriodStatus;
use Modules\Ledger\Events\JournalPosted;
use Modules\Ledger\Events\JournalReversed;
use Modules\Ledger\Exceptions\AlreadyReversed;
use Modules\Ledger\Exceptions\ClosedPeriod;
use Modules\Ledger\Exceptions\InvalidJournal;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerBalance;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Models\LedgerLine;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Ledger\Money\Money;

/**
 * The only way money enters the ledger (specs/finance/01).
 *
 * - post(): atomic and idempotent by posting key; validates balance first; updates running
 *   balances in the same transaction with row locks.
 * - reverse(): corrections are new journals with sides swapped, linked both ways.
 * - balance(): current or as-of balance in the account's natural sign.
 */
final class LedgerService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $tenants,
    ) {}

    public function post(JournalDraft $draft): LedgerJournal
    {
        $draft->validate();

        $this->assertAccountsBelongToCurrentTenant($draft);

        try {
            return $this->connection()->transaction(fn (): LedgerJournal => $this->write($draft));
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request posted the same key first: return that journal.
            // Any other unique violation is a real error and is rethrown.
            return LedgerJournal::query()->where('posting_key', $draft->postingKey)->first() ?? throw $exception;
        }
    }

    public function reverse(LedgerJournal $journal, string $reason, ?int $by = null): LedgerJournal
    {
        if ($journal->isReversed() || $journal->isReversal()) {
            throw new AlreadyReversed("Journal {$journal->ulid} cannot be reversed again.");
        }

        return $this->connection()->transaction(function () use ($journal, $reason, $by): LedgerJournal {
            $draft = JournalDraft::make('reversal.'.$journal->journal_type, 'reversal:'.$journal->ulid)
                ->describe('Reversal: '.$reason)
                ->by($by)
                ->reversing($journal->id);

            if ($journal->source_type !== null && $journal->source_id !== null) {
                $draft->source($journal->source_type, $journal->source_id);
            }

            foreach ($journal->lines()->with('account')->get() as $line) {
                /** @var LedgerAccount $account */
                $account = $line->account;
                $line->debit_cents > 0
                    ? $draft->credit($account, $line->debit(), $line->memo)
                    : $draft->debit($account, $line->credit(), $line->memo);
            }

            $draft->validate();
            $this->assertAccountsBelongToCurrentTenant($draft);
            $reversal = $this->write($draft);

            $journal->reversed_by_id = $reversal->id;
            $journal->save();

            $this->connection()->afterCommit(fn () => JournalReversed::dispatch(
                $journal->tenant_id, $journal->ulid, $reversal->ulid, $reason,
            ));

            return $reversal;
        });
    }

    /**
     * Balance in the account's natural sign (assets and expenses positive when debited;
     * liabilities, equity and revenue positive when credited).
     */
    public function balance(LedgerAccount $account, ?CarbonImmutable $asOf = null, string $currency = 'USD'): Money
    {
        $netDebit = $asOf === null
            ? (int) (LedgerBalance::query()->whereKey($account->id)->value('net_debit_cents') ?? 0)
            : $this->netDebitFromLines($account, $asOf);

        return Money::of($account->normal_side === NormalSide::Debit ? $netDebit : -$netDebit, $currency);
    }

    /**
     * Net debit recomputed from lines, optionally up to an effective date (used by reconciliation).
     */
    public function netDebitFromLines(LedgerAccount $account, ?CarbonImmutable $asOf = null): int
    {
        $query = LedgerLine::query()->where('account_id', $account->id);

        if ($asOf !== null) {
            $query->whereHas('journal', fn ($q) => $q->whereDate('effective_date', '<=', $asOf->toDateString()));
        }

        return (int) $query->sum('debit_cents') - (int) $query->clone()->sum('credit_cents');
    }

    private function write(JournalDraft $draft): LedgerJournal
    {
        $existing = LedgerJournal::query()->where('posting_key', $draft->postingKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $period = $this->periodFor($draft->effectiveDate());

        $journal = LedgerJournal::query()->create([
            'journal_type' => $draft->type,
            'source_type' => $draft->sourceType(),
            'source_id' => $draft->sourceId(),
            'posting_key' => $draft->postingKey,
            'effective_date' => $draft->effectiveDate(),
            'posted_at' => CarbonImmutable::now(),
            'description' => $draft->description(),
            'reversal_of_id' => $draft->reversalOf(),
            'period_id' => $period->id,
            'created_by' => $draft->createdBy(),
        ]);

        /** @var array<int, array{net: int, currency: string}> $deltas */
        $deltas = [];

        foreach ($draft->lines() as $line) {
            $amount = $line['debit'] ?? $line['credit'];
            assert($amount !== null);
            $isDebit = $line['debit'] !== null;

            LedgerLine::query()->create([
                'journal_id' => $journal->id,
                'account_id' => $line['account']->id,
                'debit_cents' => $isDebit ? $amount->cents : 0,
                'credit_cents' => $isDebit ? 0 : $amount->cents,
                'currency' => $amount->currency,
                'memo' => $line['memo'],
            ]);

            $deltas[$line['account']->id] ??= ['net' => 0, 'currency' => $amount->currency];
            $deltas[$line['account']->id]['net'] += $isDebit ? $amount->cents : -$amount->cents;
        }

        // Lock balances in a stable order to avoid deadlocks between concurrent postings.
        ksort($deltas);
        foreach ($deltas as $accountId => $delta) {
            $balance = LedgerBalance::query()->whereKey($accountId)->lockForUpdate()->first()
                ?? LedgerBalance::query()->create(['account_id' => $accountId, 'currency' => $delta['currency']]);

            $balance->net_debit_cents += $delta['net'];
            $balance->last_journal_id = $journal->id;
            $balance->lock_version++;
            $balance->save();
        }

        $this->connection()->afterCommit(fn () => JournalPosted::dispatch(
            $journal->tenant_id, $journal->ulid, $journal->journal_type,
        ));

        return $journal;
    }

    private function periodFor(CarbonImmutable $date): LedgerPeriod
    {
        $start = $date->startOfMonth();

        $period = LedgerPeriod::query()->whereDate('starts_on', $start->toDateString())->first()
            ?? LedgerPeriod::query()->create([
                'starts_on' => $start,
                'ends_on' => $start->endOfMonth()->startOfDay(),
                'status' => PeriodStatus::Open,
            ]);

        if ($period->isClosed()) {
            throw new ClosedPeriod("The period starting {$start->toDateString()} is closed.");
        }

        return $period;
    }

    /**
     * Account objects passed in by callers bypass query scoping, so check ownership explicitly:
     * a journal may only touch the current tenant's accounts.
     */
    private function assertAccountsBelongToCurrentTenant(JournalDraft $draft): void
    {
        $tenantId = $this->tenants->id();

        foreach ($draft->lines() as $line) {
            if ($line['account']->tenant_id !== $tenantId) {
                throw new InvalidJournal("Account {$line['account']->ulid} does not belong to the current tenant.");
            }
        }
    }

    private function connection(): Connection
    {
        // Touch the tenant context so posting without a tenant fails before any query.
        $this->tenants->current();

        return $this->db->connection(Connections::cell());
    }
}
