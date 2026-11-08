<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Data\Statement;
use Modules\Ledger\Data\StatementLine;
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
    /** Retries of the posting transaction on deadlock or lock timeout. */
    private const ATTEMPTS = 5;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $tenants,
    ) {}

    public function post(JournalDraft $draft): LedgerJournal
    {
        $draft->validate();

        $this->assertAccountsBelongToCurrentTenant($draft);
        $this->prepare($draft);

        try {
            return $this->connection()->transaction(fn (): LedgerJournal => $this->write($draft), self::ATTEMPTS);
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
        $this->prepare($draft);

        return $this->connection()->transaction(function () use ($journal, $draft, $reason): LedgerJournal {
            $reversal = $this->write($draft);

            $journal->reversed_by_id = $reversal->id;
            $journal->save();

            $this->connection()->afterCommit(fn () => JournalReversed::dispatch(
                $journal->tenant_id, $journal->ulid, $reversal->ulid, $reason,
            ));

            return $reversal;
        }, self::ATTEMPTS);
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
     * Statement for an account between two dates (inclusive), in the account's natural sign.
     */
    public function statement(LedgerAccount $account, CarbonImmutable $from, CarbonImmutable $to, string $currency = 'USD'): Statement
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $sign = $account->normal_side === NormalSide::Debit ? 1 : -1;

        $opening = Money::of($sign * $this->netDebitFromLines($account, $from->subDay()), $currency);
        $running = $opening;
        $lines = [];

        $rows = LedgerLine::query()
            ->select('ledger_lines.*', 'ledger_journals.ulid as journal_ulid', 'ledger_journals.journal_type',
                'ledger_journals.description', 'ledger_journals.effective_date')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_lines.journal_id')
            ->where('ledger_lines.account_id', $account->id)
            ->whereDate('ledger_journals.effective_date', '>=', $from->toDateString())
            ->whereDate('ledger_journals.effective_date', '<=', $to->toDateString())
            ->orderBy('ledger_journals.effective_date')
            ->orderBy('ledger_journals.id')
            ->orderBy('ledger_lines.id')
            ->get();

        foreach ($rows as $row) {
            $amount = Money::of($sign * ($row->debit_cents - $row->credit_cents), $row->currency);
            $running = $running->add($amount);

            $lines[] = new StatementLine(
                CarbonImmutable::parse((string) $row->getAttribute('effective_date')),
                (string) $row->getAttribute('journal_ulid'),
                (string) $row->getAttribute('journal_type'),
                $row->getAttribute('description') !== null ? (string) $row->getAttribute('description') : null,
                $row->memo,
                $amount,
                $running,
            );
        }

        return new Statement($from, $to, $opening, $lines, $running);
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

        // Shared lock: a period cannot be closed while a posting into it is in progress.
        $period = LedgerPeriod::query()
            ->whereDate('starts_on', $draft->effectiveDate()->startOfMonth()->toDateString())
            ->sharedLock()
            ->firstOrFail();

        if ($period->isClosed()) {
            throw new ClosedPeriod("The period starting {$period->starts_on->toDateString()} is closed.");
        }

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
            $balance = LedgerBalance::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();

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

    /**
     * Make sure the month's period and every touched balance row exist BEFORE the posting
     * transaction. Concurrent first postings race to create them; the loser's unique-key error is
     * expected and ignored, so the posting transaction itself never has to create shared rows
     * (which would cause duplicate-key failures or InnoDB gap-lock deadlocks under load).
     */
    private function prepare(JournalDraft $draft): void
    {
        $start = $draft->effectiveDate()->startOfMonth();

        if (! LedgerPeriod::query()->whereDate('starts_on', $start->toDateString())->exists()) {
            $this->createIgnoringRace(fn () => LedgerPeriod::query()->create([
                'starts_on' => $start,
                'ends_on' => $start->endOfMonth()->startOfDay(),
                'status' => PeriodStatus::Open,
            ]));
        }

        foreach ($draft->lines() as $line) {
            $amount = $line['debit'] ?? $line['credit'];
            assert($amount !== null);
            $accountId = $line['account']->id;

            if (! LedgerBalance::query()->whereKey($accountId)->exists()) {
                $this->createIgnoringRace(fn () => LedgerBalance::query()->create([
                    'account_id' => $accountId,
                    'currency' => $amount->currency,
                ]));
            }
        }
    }

    private function createIgnoringRace(\Closure $create): void
    {
        try {
            $create();
        } catch (UniqueConstraintViolationException) {
            // Another process created it first; that is fine.
        }
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
