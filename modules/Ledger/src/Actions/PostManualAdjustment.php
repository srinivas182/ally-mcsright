<?php

declare(strict_types=1);

namespace Modules\Ledger\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Ledger\Exceptions\ClosedPeriod;
use Modules\Ledger\Exceptions\InvalidJournal;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Money\Money;

/**
 * Manual journal entered by an accountant. Always flagged as a manual adjustment, requires a
 * description, and is idempotent per form submission. The approval step (approval rule
 * finance.ledger.adjust) is added when approval rules ship (specs/core/03, Sprints 5-6).
 */
final class PostManualAdjustment
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @param  list<array{account: string, side: string, amount: string, memo?: string|null}>  $lines
     */
    public function handle(string $description, CarbonImmutable $effectiveDate, array $lines, string $idempotencyKey, ?int $byUserId): LedgerJournal
    {
        if (trim($description) === '') {
            throw ValidationException::withMessages(['description' => 'Describe why this adjustment is needed.']);
        }

        $draft = JournalDraft::make('manual.adjustment', 'manual:'.$idempotencyKey)
            ->effectiveOn($effectiveDate)
            ->describe(trim($description))
            ->by($byUserId);

        foreach ($lines as $i => $line) {
            $account = LedgerAccount::query()->where('ulid', $line['account'])->first()
                ?? throw ValidationException::withMessages(["lines.{$i}.account" => 'Choose an account.']);

            try {
                $amount = Money::fromDecimal($line['amount']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["lines.{$i}.amount" => 'Enter an amount like 1250.00.']);
            }

            $line['side'] === 'debit'
                ? $draft->debit($account, $amount, $line['memo'] ?? null)
                : $draft->credit($account, $amount, $line['memo'] ?? null);
        }

        try {
            return $this->ledger->post($draft);
        } catch (InvalidJournal $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (ClosedPeriod) {
            throw ValidationException::withMessages(['effective_date' => 'That month is closed. Use a date in an open period.']);
        }
    }
}
