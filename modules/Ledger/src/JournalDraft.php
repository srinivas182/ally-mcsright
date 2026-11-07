<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Carbon\CarbonImmutable;
use Modules\Ledger\Exceptions\InvalidJournal;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Money\Money;

/**
 * Builder for a journal to post. Validated in full before anything touches the database.
 *
 *   JournalDraft::make('commission.calculated', "deal:{$deal->ulid}:v1")
 *       ->debit($receivable, $gci, 'GCI from title')
 *       ->credit($agentPayable, $agentShare)
 *       ->credit($companyDollar, $companyShare)
 *       ->source('deal', $deal->ulid);
 */
final class JournalDraft
{
    /** @var list<array{account: LedgerAccount, debit: Money|null, credit: Money|null, memo: string|null}> */
    private array $lines = [];

    private CarbonImmutable $effectiveDate;

    private ?string $sourceType = null;

    private ?string $sourceId = null;

    private ?string $description = null;

    private ?int $createdBy = null;

    private ?int $reversalOf = null;

    private function __construct(
        public readonly string $type,
        public readonly string $postingKey,
    ) {
        $this->effectiveDate = CarbonImmutable::today();
    }

    public static function make(string $type, string $postingKey): self
    {
        if (trim($postingKey) === '' || strlen($postingKey) > 191) {
            throw new InvalidJournal('A posting key of 1-191 characters is required.');
        }

        return new self($type, $postingKey);
    }

    public function debit(LedgerAccount $account, Money $amount, ?string $memo = null): self
    {
        $this->lines[] = ['account' => $account, 'debit' => $amount, 'credit' => null, 'memo' => $memo];

        return $this;
    }

    public function credit(LedgerAccount $account, Money $amount, ?string $memo = null): self
    {
        $this->lines[] = ['account' => $account, 'debit' => null, 'credit' => $amount, 'memo' => $memo];

        return $this;
    }

    public function effectiveOn(CarbonImmutable $date): self
    {
        $this->effectiveDate = $date->startOfDay();

        return $this;
    }

    public function source(string $type, string $id): self
    {
        $this->sourceType = $type;
        $this->sourceId = $id;

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function by(?int $userId): self
    {
        $this->createdBy = $userId;

        return $this;
    }

    /**
     * @internal used by LedgerService::reverse()
     */
    public function reversing(int $journalId): self
    {
        $this->reversalOf = $journalId;

        return $this;
    }

    /**
     * Throws InvalidJournal unless the draft has at least two lines, every line is a single
     * positive debit or credit on an active account, and debits equal credits per currency.
     */
    public function validate(): void
    {
        if (count($this->lines) < 2) {
            throw new InvalidJournal('A journal needs at least two lines.');
        }

        $totals = [];

        foreach ($this->lines as $i => $line) {
            $amount = $line['debit'] ?? $line['credit'];

            if ($amount === null || ($line['debit'] !== null && $line['credit'] !== null)) {
                throw new InvalidJournal("Line {$i} must be either a debit or a credit.");
            }

            if (! $amount->isPositive()) {
                throw new InvalidJournal("Line {$i} amount must be greater than zero.");
            }

            if (! $line['account']->isActive()) {
                throw new InvalidJournal("Account {$line['account']->code} is not active.");
            }

            $totals[$amount->currency] ??= ['debit' => 0, 'credit' => 0];
            $totals[$amount->currency][$line['debit'] !== null ? 'debit' : 'credit'] += $amount->cents;
        }

        foreach ($totals as $currency => $sides) {
            if ($sides['debit'] !== $sides['credit']) {
                throw new InvalidJournal("Journal does not balance in {$currency}: debits {$sides['debit']} vs credits {$sides['credit']} (cents).");
            }
        }
    }

    /**
     * @return list<array{account: LedgerAccount, debit: Money|null, credit: Money|null, memo: string|null}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function effectiveDate(): CarbonImmutable
    {
        return $this->effectiveDate;
    }

    public function sourceType(): ?string
    {
        return $this->sourceType;
    }

    public function sourceId(): ?string
    {
        return $this->sourceId;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function createdBy(): ?int
    {
        return $this->createdBy;
    }

    public function reversalOf(): ?int
    {
        return $this->reversalOf;
    }
}
