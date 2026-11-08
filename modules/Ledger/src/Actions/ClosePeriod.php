<?php

declare(strict_types=1);

namespace Modules\Ledger\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ledger\Enums\PeriodStatus;
use Modules\Ledger\Models\LedgerPeriod;

/**
 * Closes a finished accounting month. Closed periods never reopen; corrections are posted in
 * the current open period (specs/finance/01).
 */
final class ClosePeriod
{
    public function handle(LedgerPeriod $period, ?int $byUserId): LedgerPeriod
    {
        // Exclusive lock waits for postings holding a shared lock on this period to finish.
        return DB::connection($period->getConnectionName())->transaction(function () use ($period, $byUserId): LedgerPeriod {
            $period = LedgerPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            return $this->close($period, $byUserId);
        });
    }

    private function close(LedgerPeriod $period, ?int $byUserId): LedgerPeriod
    {
        if ($period->isClosed()) {
            throw ValidationException::withMessages(['period' => 'This period is already closed.']);
        }

        if (! $period->ends_on->isBefore(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['period' => 'A period can only be closed after its last day.']);
        }

        $period->forceFill([
            'status' => PeriodStatus::Closed,
            'closed_by' => $byUserId,
            'closed_at' => CarbonImmutable::now(),
        ])->save();

        return $period;
    }
}
