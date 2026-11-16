<?php

declare(strict_types=1);

namespace Modules\Transactions\Timeline;

use Carbon\CarbonImmutable;

/**
 * State rule packs for the smart timeline (specs/ai/02, §8), kept by RightAlly HQ. Each pack says whether a deadline
 * that lands on a weekend or holiday moves to the next business day, the state's own holidays, the standard earnest
 * money window where the state's promulgated form sets one, and any attorney-review period. Anything a contract says
 * itself always wins; these fill the gaps and are labelled so the agent can check them.
 */
final class StatePacks
{
    /**
     * @var array<string, array{name: string, roll_forward: bool, earnest_money_days: int|null, attorney_review_business_days: int|null, holidays: list<string>, source: string}>
     */
    private const PACKS = [
        'TX' => ['name' => 'Texas', 'roll_forward' => true, 'earnest_money_days' => 3, 'attorney_review_business_days' => null,
            'holidays' => ['03-02', '04-21', '08-27'], 'source' => 'TREC contract forms: periods ending on a weekend or holiday run to the next day that is not'],
        'LA' => ['name' => 'Louisiana', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => null, 'holidays' => [],
            'source' => 'LREC purchase agreement: deadlines on a weekend or legal holiday move to the next business day'],
        'MS' => ['name' => 'Mississippi', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => null, 'holidays' => [],
            'source' => 'Common Mississippi contract practice; check the contract'],
        'AL' => ['name' => 'Alabama', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => null, 'holidays' => [],
            'source' => 'Common Alabama contract practice; check the contract'],
        'NJ' => ['name' => 'New Jersey', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => 3, 'holidays' => [],
            'source' => 'New Jersey three-business-day attorney review'],
    ];

    private const DEFAULT = ['name' => 'Other states', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => null, 'holidays' => [],
        'source' => 'General practice; check the contract'];

    /**
     * @return array{name: string, roll_forward: bool, earnest_money_days: int|null, attorney_review_business_days: int|null, holidays: list<string>, source: string}
     */
    public static function for(?string $state): array
    {
        return self::PACKS[strtoupper((string) $state)] ?? self::DEFAULT;
    }

    public static function isBusinessDay(CarbonImmutable $day, ?string $state): bool
    {
        return ! $day->isWeekend() && ! in_array($day->toDateString(), self::holidays($day->year, $state), true);
    }

    /** A deadline moved to the next business day when the state's pack says so. */
    public static function roll(CarbonImmutable $day, ?string $state): CarbonImmutable
    {
        if (! self::for($state)['roll_forward']) {
            return $day;
        }
        for ($i = 0; $i < 10 && ! self::isBusinessDay($day, $state); $i++) {
            $day = $day->addDay();
        }

        return $day;
    }

    public static function addBusinessDays(CarbonImmutable $day, int $n, ?string $state): CarbonImmutable
    {
        while ($n > 0) {
            $day = $day->addDay();
            if (self::isBusinessDay($day, $state)) {
                $n--;
            }
        }

        return $day;
    }

    /**
     * Federal holidays (observed on the nearest weekday) plus the state's own.
     *
     * @return list<string>
     */
    public static function holidays(int $year, ?string $state): array
    {
        // The nth weekday (1 = Monday … 7 = Sunday) of a month; n = -1 for the last one.
        $nth = function (int $month, int $weekday, int $n) use ($year): CarbonImmutable {
            if ($n === -1) {
                $d = CarbonImmutable::parse(sprintf('%d-%02d-01', $year, $month))->endOfMonth()->startOfDay();
                while ($d->dayOfWeekIso !== $weekday) {
                    $d = $d->subDay();
                }

                return $d;
            }
            $d = CarbonImmutable::parse(sprintf('%d-%02d-01', $year, $month));
            while ($d->dayOfWeekIso !== $weekday) {
                $d = $d->addDay();
            }

            return $d->addWeeks($n - 1);
        };
        $fixed = fn (int $month, int $day): CarbonImmutable => CarbonImmutable::parse(sprintf('%d-%02d-%02d', $year, $month, $day));
        $observed = fn (CarbonImmutable $d): CarbonImmutable => $d->isSaturday() ? $d->subDay() : ($d->isSunday() ? $d->addDay() : $d);
        $days = [
            $observed($fixed(1, 1)), $nth(1, 1, 3), $nth(2, 1, 3), $nth(5, 1, -1), $observed($fixed(6, 19)), $observed($fixed(7, 4)), $nth(9, 1, 1), $nth(10, 1, 2),
            $observed($fixed(11, 11)), $nth(11, 4, 4), $observed($fixed(12, 25)),
        ];
        foreach (self::for($state)['holidays'] as $md) {
            $days[] = CarbonImmutable::parse("{$year}-{$md}");
        }

        return array_values(array_unique(array_map(fn (CarbonImmutable $d): string => $d->toDateString(), $days)));
    }
}
