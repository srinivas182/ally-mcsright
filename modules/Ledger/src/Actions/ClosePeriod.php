<?php

declare(strict_types=1);

namespace Modules\Ledger\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Ledger\Close\CloseChecks;
use Modules\Ledger\Close\Finding;
use Modules\Ledger\Close\Snapshots;
use Modules\Ledger\Enums\PeriodStatus;
use Modules\Ledger\Models\LedgerPeriod;

/**
 * Closes a finished accounting month. Closed periods never reopen; corrections are posted in the current
 * open period (specs/finance/01). FH1 (specs/finance/22): the month-end checklist runs first. Anything
 * marked "stop" blocks the close; anything to "check" needs a written note, kept with the month. A copy
 * of the statements is kept as they were at close (and for the year, when December closes).
 */
final class ClosePeriod
{
    public function __construct(private readonly CloseChecks $checks, private readonly Snapshots $snapshots) {}

    public function handle(LedgerPeriod $period, ?int $byUserId, ?string $note = null): LedgerPeriod
    {
        if ($period->isClosed()) {
            throw ValidationException::withMessages(['period' => 'This period is already closed.']);
        }
        if (! $period->ends_on->isBefore(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['period' => 'A period can only be closed after its last day.']);
        }
        $earlier = LedgerPeriod::query()->where('status', PeriodStatus::Open)->whereDate('starts_on', '<', $period->starts_on->toDateString())->orderBy('starts_on')->first();
        if ($earlier !== null) {
            throw ValidationException::withMessages(['period' => 'Close '.$earlier->starts_on->format('F Y').' first. Months close in order.']);
        }
        $result = $this->checks->refresh($period);
        $stops = array_values(array_filter($result['findings'], fn (array $f): bool => $f['level'] === Finding::STOP));
        if ($stops !== []) {
            throw ValidationException::withMessages(['period' => count($stops) === 1 ? 'Fix this first: '.$stops[0]['title'].'.'
                : 'Fix the '.count($stops).' things marked "Must fix" first.']);
        }
        $toCheck = array_values(array_filter($result['findings'], fn (array $f): bool => $f['level'] === Finding::CHECK));
        $note = $note !== null ? trim($note) : '';
        if ($toCheck !== [] && mb_strlen($note) < 10) {
            throw ValidationException::withMessages(['note' => 'Write why it is OK to close with '.(count($toCheck) === 1 ? 'this item' : 'these '.count($toCheck).' items').' to check (at least 10 characters).']);
        }

        // Exclusive lock waits for postings holding a shared lock on this period to finish.
        $period = DB::connection($period->getConnectionName())->transaction(function () use ($period, $byUserId, $toCheck, $note): LedgerPeriod {
            $period = LedgerPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->isClosed()) {
                throw ValidationException::withMessages(['period' => 'This period is already closed.']);
            }
            $period->forceFill([
                'status' => PeriodStatus::Closed,
                'closed_by' => $byUserId,
                'closed_at' => CarbonImmutable::now(),
                'close_notes' => $toCheck === [] ? null : ['note' => mb_substr($note, 0, 1000),
                    'items' => array_map(fn (array $f): array => ['title' => $f['title'], 'detail' => $f['detail']], $toCheck)],
            ])->save();
            $this->snapshots->take($period);

            return $period;
        });
        app(AuditLogger::class)->record('finance.period.closed', metadata: ['month' => $period->starts_on->format('Y-m'), 'items_to_check' => count($toCheck)],
            severity: $toCheck === [] ? 'notice' : 'warning');

        return $period;
    }
}
