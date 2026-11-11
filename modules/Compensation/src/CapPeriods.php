<?php

declare(strict_types=1);

namespace Modules\Compensation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Compensation\Models\CapPeriod;
use Modules\Compensation\Rules\Cap;

/**
 * Finds or opens an agent's cap period for a date. A period covering the date always wins, so a
 * plan change mid-year keeps the current period (company dollar already paid still counts).
 */
final class CapPeriods
{
    public function periodFor(int $agentUserId, Cap $cap, CarbonImmutable $date): CapPeriod
    {
        $existing = CapPeriod::query()->where('agent_user_id', $agentUserId)
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->orderByDesc('starts_on')->first();

        if ($existing !== null) {
            return $existing;
        }

        $start = $this->startFor($agentUserId, $cap, $date);

        return CapPeriod::query()->create([
            'agent_user_id' => $agentUserId,
            'starts_on' => $start,
            'ends_on' => $start->addYear()->subDay(),
            'cap_amount_cents' => $cap->amountCents,
            'company_dollar_cents' => 0,
            'post_cap_fees_cents' => 0,
            'status' => 'open',
        ]);
    }

    public function startFor(int $agentUserId, Cap $cap, CarbonImmutable $date): CarbonImmutable
    {
        if ($cap->anchor === 'fixed' && $cap->anchorMonthDay !== null) {
            [$month, $day] = array_map('intval', explode('-', $cap->anchorMonthDay));
        } else {
            $joined = User::query()->whereKey($agentUserId)->value('created_at');
            $joinedAt = $joined !== null ? CarbonImmutable::parse((string) $joined) : $date;
            [$month, $day] = [(int) $joinedAt->month, (int) $joinedAt->day];
        }

        $candidate = $this->onDay($date->year, $month, $day);

        return $candidate->gt($date->startOfDay()) ? $this->onDay($date->year - 1, $month, $day) : $candidate;
    }

    /** 29 February becomes 28 February in non-leap years. */
    private function onDay(int $year, int $month, int $day): CarbonImmutable
    {
        $first = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();

        return $first->setDay(min($day, $first->daysInMonth));
    }
}
