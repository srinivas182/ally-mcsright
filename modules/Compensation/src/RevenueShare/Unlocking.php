<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Models\CapPeriod;
use Modules\Compensation\Rules\RevenueShareRules;
use Modules\Core\Support\Connections;

/**
 * Which levels a sponsor has unlocked on a date (specs/compensation/02, addition 1): levels up to
 * open_levels are open; a deeper level opens when the sponsor has enough qualifying front-line agents
 * (direct sponsorships who closed enough deals or earned enough GCI in the window), or when the
 * sponsor capped within the production window (opens levels up to through_level).
 */
final class Unlocking
{
    /** @var array<string, int> */
    private array $qualifyingCache = [];

    /** @var array<string, array{closings: int, gci: int}> */
    private array $productionCache = [];

    public function open(int $sponsorId, int $level, RevenueShareRules $rules, CarbonImmutable $on): bool
    {
        if ($rules->openLevels === null || $level <= $rules->openLevels) {
            return true;
        }
        if ($rules->productionThroughLevel !== null && $level <= $rules->productionThroughLevel && $this->cappedRecently($sponsorId, $on, $rules->productionMonths)) {
            return true;
        }
        $needed = null;
        foreach ($rules->unlockThresholds as $l => $count) {
            if ($l <= $level) {
                $needed = $count;
            }
        }

        return $needed !== null && $this->qualifyingFrontLine($sponsorId, $rules, $on) >= $needed;
    }

    public function qualifyingFrontLine(int $sponsorId, RevenueShareRules $rules, CarbonImmutable $on): int
    {
        $key = "{$sponsorId}:{$on->toDateString()}:{$rules->qualifyingMinClosings}:{$rules->qualifyingMinGciCents}:{$rules->qualifyingWindowMonths}";
        if (isset($this->qualifyingCache[$key])) {
            return $this->qualifyingCache[$key];
        }
        $count = 0;
        foreach ($this->frontLine($sponsorId, $on) as $agentId) {
            $p = $this->production($agentId, $on->subMonthsNoOverflow($rules->qualifyingWindowMonths), $on);
            if (($rules->qualifyingMinClosings > 0 && $p['closings'] >= $rules->qualifyingMinClosings)
                || ($rules->qualifyingMinGciCents > 0 && $p['gci'] >= $rules->qualifyingMinGciCents)) {
                $count++;
            }
        }

        return $this->qualifyingCache[$key] = $count;
    }

    /**
     * @return list<int>
     */
    public function frontLine(int $sponsorId, CarbonImmutable $on): array
    {
        $ids = DB::connection(Connections::cell())->table('rs_sponsorships')->where('sponsor_user_id', $sponsorId)
            ->where('effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on->toDateString()))
            ->pluck('agent_user_id')->map(fn ($v): int => (int) $v)->unique()->all();

        return array_values($ids);
    }

    /**
     * Closed deals and GCI for an agent between two dates (finalised calculations).
     *
     * @return array{closings: int, gci: int}
     */
    public function production(int $agentId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = "{$agentId}:{$from->toDateString()}:{$to->toDateString()}";
        if (isset($this->productionCache[$key])) {
            return $this->productionCache[$key];
        }
        $closings = 0;
        $gci = 0;
        $ids = DB::connection(Connections::cell())->table('comp_calculation_agents')->where('agent_user_id', $agentId)->where('status', 'final')->pluck('calculation_id');
        foreach (Calculation::query()->whereIn('id', $ids)->where('status', 'final')->get() as $calc) {
            $date = (string) ($calc->inputs['closing_date'] ?? '');
            if ($date < $from->toDateString() || $date > $to->toDateString()) {
                continue;
            }
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                if ((int) $row['user_id'] === $agentId) {
                    $closings++;
                    $gci += (int) $row['share_cents'];
                }
            }
        }

        return $this->productionCache[$key] = ['closings' => $closings, 'gci' => $gci];
    }

    private function cappedRecently(int $sponsorId, CarbonImmutable $on, int $months): bool
    {
        return CapPeriod::query()->where('agent_user_id', $sponsorId)->whereNotNull('cap_amount_cents')
            ->whereDate('starts_on', '<=', $on->toDateString())->whereDate('ends_on', '>=', $on->subMonthsNoOverflow($months)->toDateString())
            ->whereColumn('company_dollar_cents', '>=', 'cap_amount_cents')->exists();
    }

    public function forget(): void
    {
        $this->qualifyingCache = [];
        $this->productionCache = [];
    }
}
