<?php

declare(strict_types=1);

namespace Modules\Transactions\Timeline;

use Carbon\CarbonImmutable;

/**
 * State rule packs for the smart timeline (specs/ai/02, §8), kept by RightAlly HQ. Each pack says whether a deadline
 * that lands on a weekend or holiday moves to the next business day, the state's own holidays, the standard earnest
 * money window where the state's promulgated form sets one, and any attorney-review period. Anything a contract says
 * itself always wins; these fill the gaps and are labelled so the agent can check them.
 *
 * DQ4 (D14): nine more states (FL, CA, GA, NC, IL, AZ, CO, WA, NY), and "periods": the standard form's default
 * windows (label, days, business days?) used only when the contract read by DocIQ does not give that date. These are
 * defaults to be reviewed by each brokerage's broker or attorney; the contract always wins.
 */
final class StatePacks
{
    /**
     * @var array<string, array{name: string, roll_forward: bool, earnest_money_days: int|null, attorney_review_business_days: int|null, holidays: list<string>, source: string, earnest_money_business?: bool, periods?: list<array{0: string, 1: int, 2: bool}>}>
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
        // DQ4 (D14).
        'FL' => ['name' => 'Florida', 'roll_forward' => true, 'earnest_money_days' => 3, 'attorney_review_business_days' => null, 'holidays' => [],
            'periods' => [['Inspection deadline', 15, false], ['Financing deadline', 30, false]],
            'source' => 'Florida Realtors/Florida Bar contracts: initial deposit within 3 days of the effective date; deadlines on a weekend or national holiday move to the next business day; 15-day inspection period and 30-day loan approval unless the contract says otherwise'],
        'CA' => ['name' => 'California', 'roll_forward' => true, 'earnest_money_days' => 3, 'earnest_money_business' => true, 'attorney_review_business_days' => null, 'holidays' => [],
            'periods' => [['Seller disclosures due', 7, false], ['Inspection deadline', 17, false], ['Appraisal deadline', 17, false], ['Financing deadline', 17, false]],
            'source' => 'C.A.R. Residential Purchase Agreement: deposit within 3 business days after acceptance; disclosures within 7 days; 17-day investigation, appraisal and loan contingencies unless the contract says otherwise'],
        'GA' => ['name' => 'Georgia', 'roll_forward' => true, 'earnest_money_days' => 3, 'earnest_money_business' => true, 'attorney_review_business_days' => null, 'holidays' => [],
            'source' => 'Georgia REALTORS purchase agreement: earnest money within 3 banking days of the binding agreement date; the due diligence period is set in the contract'],
        'NC' => ['name' => 'North Carolina', 'roll_forward' => true, 'earnest_money_days' => 5, 'attorney_review_business_days' => null, 'holidays' => [],
            'source' => 'NC REALTORS/NC Bar Offer to Purchase (Form 2-T): earnest money within 5 days of the effective date; the due diligence period is set in the contract'],
        'IL' => ['name' => 'Illinois', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => 5, 'holidays' => [],
            'periods' => [['Inspection deadline', 5, true]],
            'source' => 'Illinois Multi-Board contract: 5-business-day attorney review and inspection periods unless the contract says otherwise'],
        'AZ' => ['name' => 'Arizona', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => null, 'holidays' => [],
            'periods' => [['Inspection deadline', 10, false]],
            'source' => 'Arizona REALTORS purchase contract: 10-day inspection period unless the contract says otherwise'],
        'CO' => ['name' => 'Colorado', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => null, 'holidays' => [],
            'source' => 'Colorado commission-approved contract: every deadline is a date in the contract; check whether it extends weekend and holiday deadlines'],
        'WA' => ['name' => 'Washington', 'roll_forward' => true, 'earnest_money_days' => 2, 'earnest_money_business' => true, 'attorney_review_business_days' => null, 'holidays' => [],
            'periods' => [['Inspection deadline', 10, false]],
            'source' => 'NWMLS purchase agreement: earnest money within 2 days of mutual acceptance; 10-day inspection contingency unless the contract says otherwise'],
        'NY' => ['name' => 'New York', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => null, 'holidays' => [],
            'source' => 'New York: attorneys draft the contract after the accepted offer; take every date from the signed contract'],
    ];

    private const DEFAULT = ['name' => 'Other states', 'roll_forward' => true, 'earnest_money_days' => null, 'attorney_review_business_days' => null, 'holidays' => [],
        'source' => 'General practice; check the contract'];

    /**
     * @return array{name: string, roll_forward: bool, earnest_money_days: int|null, attorney_review_business_days: int|null, holidays: list<string>, source: string, earnest_money_business?: bool, periods?: list<array{0: string, 1: int, 2: bool}>}
     */
    public static function for(?string $state): array
    {
        return self::PACKS[strtoupper((string) $state)] ?? self::DEFAULT;
    }

    /**
     * Each state pack in plain words, for Compliance Knowledge answers (specs/ai/05).
     *
     * @return array<string, string> state name => rules
     */
    public static function summaries(): array
    {
        $out = [];
        foreach (self::PACKS as $code => $p) {
            $out[$p['name']] = $p['name'].' ('.$code.') deadline rules in RightAlly: deadlines that fall on a weekend or holiday move to the next business day. '
                .($p['earnest_money_days'] !== null ? "Earnest money is due within {$p['earnest_money_days']} ".(($p['earnest_money_business'] ?? false) ? 'business ' : '').'days of the contract (effective) date. ' : '')
                .implode('', array_map(fn (array $d): string => "{$d[0]}: {$d[1]} ".($d[2] ? 'business ' : '').'days after the contract date unless the contract says otherwise. ', $p['periods'] ?? []))
                .($p['attorney_review_business_days'] !== null ? "Attorney review lasts {$p['attorney_review_business_days']} business days after the contract is delivered. " : '')
                .($p['holidays'] !== [] ? 'State holidays also count: '.implode(', ', $p['holidays']).' (month-day). ' : '').'Basis: '.$p['source'].'.';
        }

        return $out;
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
