<?php

declare(strict_types=1);

namespace Modules\Compensation\Rules;

use Brick\Math\BigDecimal;

/**
 * Revenue share settings of a plan version (specs/compensation/02; legacy rules 5–9).
 *
 * - basis: net_profit (company dollar after brokerage-paid fees and credits), gci (the agent's share of
 *   GCI) or company_dollar
 * - levels: up to 15 percentages; for net_profit and company_dollar they total 100 (the whole basis is
 *   distributed), for gci they total the company revenue % (100 − agent %) — within 0.01 points
 * - missing_upline / ineligible_upline: root (credit the root account) or forfeit (missed earning)
 * - fallback_cap_cents: most the root account receives from one deal (the rest is missed)
 */
final readonly class RevenueShareRules
{
    public const MAX_LEVELS = 15;

    /**
     * @param  list<string>  $levels
     */
    public function __construct(
        public string $basis,
        public array $levels = [],
        public string $missingUpline = 'root',
        public string $ineligibleUpline = 'forfeit',
        public ?int $fallbackCapCents = null,
        public ?int $openLevels = null,            // null: every level open
        public int $qualifyingMinClosings = 2,
        public int $qualifyingMinGciCents = 500_000,
        public int $qualifyingWindowMonths = 6,
        /** @var array<int, int> level => qualifying front-line agents needed */
        public array $unlockThresholds = [],
        public ?int $productionThroughLevel = null, // capping opens levels up to this one
        public int $productionMonths = 13,
        public ?int $perDealMaxCents = null,
        public ?int $perRecipientYearlyMaxCents = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors
     */
    public static function parse(array $data, Split $split, array &$errors): ?self
    {
        $basis = $data['basis'] ?? null;
        if (! in_array($basis, ['net_profit', 'gci', 'company_dollar'], true)) {
            $errors['revenue_share.basis'] = 'Revenue share is based on net_profit, gci or company_dollar.';

            return null;
        }

        $levels = [];
        foreach ((array) ($data['levels'] ?? []) as $i => $pct) {
            if (! is_numeric($pct) || BigDecimal::of((string) $pct)->isNegative() || BigDecimal::of((string) $pct)->isGreaterThan(100)) {
                $errors["revenue_share.levels.{$i}"] = 'Each level is a percentage from 0 to 100.';

                continue;
            }
            $levels[] = (string) $pct;
        }
        if (count($levels) > self::MAX_LEVELS) {
            $errors['revenue_share.levels'] = 'Up to 15 levels.';
        }

        if ($levels !== []) {
            $total = array_reduce($levels, fn (BigDecimal $sum, string $p): BigDecimal => $sum->plus(BigDecimal::of($p)), BigDecimal::zero());
            if ($basis === 'gci') {
                if ($split->agentPercent === null) {
                    $errors['revenue_share.basis'] = 'A GCI basis needs a percentage split; use net_profit for flat-fee or tiered plans.';
                } else {
                    $company = BigDecimal::of(100)->minus(BigDecimal::of($split->agentPercent));
                    if ($total->minus($company)->abs()->isGreaterThan(BigDecimal::of('0.01'))) {
                        $errors['revenue_share.levels'] = "With a GCI basis the levels must total the company revenue ({$company}%), not {$total}%.";
                    }
                }
            } elseif ($total->minus(BigDecimal::of(100))->abs()->isGreaterThan(BigDecimal::of('0.01'))) {
                $errors['revenue_share.levels'] = "The levels must total 100%, not {$total}%.";
            }
        }

        $missing = (string) ($data['missing_upline'] ?? 'root');
        $ineligible = (string) ($data['ineligible_upline'] ?? 'forfeit');
        foreach (['missing_upline' => $missing, 'ineligible_upline' => $ineligible] as $key => $value) {
            if (! in_array($value, ['root', 'forfeit'], true)) {
                $errors["revenue_share.{$key}"] = 'Choose root (pay the root account) or forfeit (record a missed earning).';
            }
        }
        $cap = $data['fallback_cap_cents'] ?? null;
        if ($cap !== null && (! is_int($cap) || $cap < 0)) {
            $errors['revenue_share.fallback_cap_cents'] = 'The fallback cap is an amount in cents.';
        }

        // Tier unlocking (addition 1).
        $unlock = is_array($data['unlock'] ?? null) ? $data['unlock'] : [];
        $int = static function (mixed $v, string $key, int $min, array &$errors): ?int {
            if ($v === null) {
                return null;
            }
            if (! is_int($v) || $v < $min) {
                $errors["revenue_share.{$key}"] = 'Use a whole number'.($min > 0 ? " of at least {$min}" : '').'.';

                return null;
            }

            return $v;
        };
        $thresholds = [];
        foreach ((array) ($unlock['thresholds'] ?? []) as $level => $needed) {
            if (! is_numeric($level) || (int) $level < 1 || (int) $level > self::MAX_LEVELS || ! is_int($needed) || $needed < 0) {
                $errors['revenue_share.unlock.thresholds'] = 'Thresholds are level number => qualifying agents needed.';

                continue;
            }
            $thresholds[(int) $level] = $needed;
        }
        ksort($thresholds);

        // Funding limits (addition 2).
        $funding = is_array($data['funding'] ?? null) ? $data['funding'] : [];

        return new self($basis, $levels, $missing, $ineligible, is_int($cap) ? $cap : null,
            $int($unlock['open_levels'] ?? null, 'unlock.open_levels', 0, $errors),
            $int($unlock['qualifying']['min_closings'] ?? 2, 'unlock.qualifying.min_closings', 0, $errors) ?? 2,
            $int($unlock['qualifying']['min_gci_cents'] ?? 500_000, 'unlock.qualifying.min_gci_cents', 0, $errors) ?? 500_000,
            $int($unlock['qualifying']['window_months'] ?? 6, 'unlock.qualifying.window_months', 1, $errors) ?? 6,
            $thresholds,
            $int($unlock['production']['through_level'] ?? null, 'unlock.production.through_level', 1, $errors),
            $int($unlock['production']['months'] ?? 13, 'unlock.production.months', 1, $errors) ?? 13,
            $int($funding['per_deal_max_cents'] ?? null, 'funding.per_deal_max_cents', 0, $errors),
            $int($funding['per_recipient_yearly_max_cents'] ?? null, 'funding.per_recipient_yearly_max_cents', 0, $errors));
    }
}
