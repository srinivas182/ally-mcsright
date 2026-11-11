<?php

declare(strict_types=1);

namespace Modules\Compensation\Rules;

use Modules\Compensation\Enums\SplitMode;

/**
 * How one side's GCI is split between the agent and the brokerage.
 *
 * - percentage: agent percent; company percent is always 100 − agent (legacy rule 4)
 * - flat_fee:   the brokerage takes a fee by sale-price band (legacy "small / large / mega")
 * - tiered:     agent percent by tier, measured on company dollar or units so far in the cap year
 */
final readonly class Split
{
    /**
     * @param  list<array{up_to_price_cents: int|null, fee_cents: int}>  $bands
     * @param  list<array{from: int, agent_percent: string}>  $tiers
     */
    public function __construct(
        public SplitMode $mode,
        public ?string $agentPercent = null,
        public array $bands = [],
        public string $tierMeasure = 'company_dollar',
        public array $tiers = [],
    ) {}

    /**
     * @param  array<string, string>  $errors
     */
    public static function parse(mixed $data, string $path, array &$errors): ?self
    {
        if (! is_array($data)) {
            $errors[$path] = 'A split is required.';

            return null;
        }

        if (array_key_exists('company_percent', $data)) {
            $errors["{$path}.company_percent"] = 'The company percent is never entered; it is 100 minus the agent percent.';
        }

        $mode = SplitMode::tryFrom((string) ($data['mode'] ?? ''));

        return match ($mode) {
            SplitMode::Percentage => self::parsePercentage($data, $path, $errors),
            SplitMode::FlatFee => self::parseFlatFee($data, $path, $errors),
            SplitMode::Tiered => self::parseTiered($data, $path, $errors),
            null => (function () use ($path, &$errors): null {
                $errors["{$path}.mode"] = 'Choose percentage, flat_fee or tiered.';

                return null;
            })(),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors
     */
    private static function parsePercentage(array $data, string $path, array &$errors): ?self
    {
        $agent = $data['agent_percent'] ?? null;
        if (! Percent::isValid($agent)) {
            $errors["{$path}.agent_percent"] = 'Enter the agent percent as a number from 0 to 100 (up to 4 decimals), e.g. "80".';

            return null;
        }

        /** @var string $agent */
        return new self(SplitMode::Percentage, agentPercent: $agent);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors
     */
    private static function parseFlatFee(array $data, string $path, array &$errors): ?self
    {
        $bands = $data['bands'] ?? null;
        if (! is_array($bands) || $bands === []) {
            $errors["{$path}.bands"] = 'Add at least one price band.';

            return null;
        }

        $clean = [];
        $previous = -1;
        foreach (array_values($bands) as $i => $band) {
            $upTo = is_array($band) ? ($band['up_to_price_cents'] ?? null) : null;
            $fee = is_array($band) ? ($band['fee_cents'] ?? null) : null;

            if (! is_int($fee) || $fee < 0) {
                $errors["{$path}.bands.{$i}.fee_cents"] = 'Enter the fee in cents (0 or more).';

                continue;
            }

            $last = $i === count($bands) - 1;
            if ($last && $upTo !== null) {
                $errors["{$path}.bands.{$i}.up_to_price_cents"] = 'The last band must have no upper price.';
            }
            if (! $last && (! is_int($upTo) || $upTo <= $previous)) {
                $errors["{$path}.bands.{$i}.up_to_price_cents"] = 'Bands must have rising upper prices.';
            }

            $previous = is_int($upTo) ? $upTo : $previous;
            $clean[] = ['up_to_price_cents' => is_int($upTo) ? $upTo : null, 'fee_cents' => $fee];
        }

        return new self(SplitMode::FlatFee, bands: $clean);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors
     */
    private static function parseTiered(array $data, string $path, array &$errors): ?self
    {
        $measure = (string) ($data['measure'] ?? 'company_dollar');
        if (! in_array($measure, ['company_dollar', 'units'], true)) {
            $errors["{$path}.measure"] = 'Tiers are measured on company_dollar or units.';
        }

        $tiers = $data['tiers'] ?? null;
        if (! is_array($tiers) || $tiers === []) {
            $errors["{$path}.tiers"] = 'Add at least one tier.';

            return null;
        }

        $clean = [];
        $previous = -1;
        foreach (array_values($tiers) as $i => $tier) {
            $from = is_array($tier) ? ($tier['from'] ?? null) : null;
            $agent = is_array($tier) ? ($tier['agent_percent'] ?? null) : null;

            if (! is_int($from) || $from <= $previous || ($i === 0 && $from !== 0)) {
                $errors["{$path}.tiers.{$i}.from"] = 'Tiers start at 0 and rise.';
            }
            if (! Percent::isValid($agent)) {
                $errors["{$path}.tiers.{$i}.agent_percent"] = 'Enter the agent percent from 0 to 100.';
            }

            $previous = is_int($from) ? $from : $previous;
            if (is_int($from) && is_string($agent)) {
                $clean[] = ['from' => $from, 'agent_percent' => $agent];
            }
        }

        return new self(SplitMode::Tiered, tierMeasure: $measure, tiers: $clean);
    }
}
