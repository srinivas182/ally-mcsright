<?php

declare(strict_types=1);

namespace Modules\Compensation\Rules;

/**
 * The company-dollar cap per cap year and what happens after it (legacy rules 10-13).
 *
 * - amountCents: company dollar the agent pays at most per cap year
 * - anchor: cap year starts on the agent's start-date anniversary, or a fixed month-day ("01-01")
 * - postCapCalc/postCapAmount: fee per transaction after the cap (flat cents or percent of the
 *   post-cap portion), optional; postCapFeeCapCents: most post-cap fees per cap year, optional
 */
final readonly class Cap
{
    public function __construct(
        public int $amountCents,
        public string $anchor = 'anniversary',
        public ?string $anchorMonthDay = null,
        public ?string $postCapCalc = null,
        public ?string $postCapAmount = null,
        public ?int $postCapFeeCapCents = null,
    ) {}

    /**
     * @param  array<string, string>  $errors
     */
    public static function parse(mixed $cap, mixed $postCap, array &$errors): ?self
    {
        if ($cap === null) {
            if ($postCap !== null) {
                $errors['post_cap'] = 'A post-cap fee needs a cap.';
            }

            return null; // no cap (legacy -1)
        }

        if (! is_array($cap) || ! is_int($cap['amount_cents'] ?? null) || $cap['amount_cents'] <= 0) {
            $errors['cap.amount_cents'] = 'Enter the cap in cents (more than 0), or leave the cap out for no cap.';

            return null;
        }

        $anchor = (string) ($cap['anchor'] ?? 'anniversary');
        $monthDay = $cap['anchor_month_day'] ?? null;
        if (! in_array($anchor, ['anniversary', 'fixed'], true)) {
            $errors['cap.anchor'] = 'The cap year starts on the agent\'s anniversary or a fixed date.';
        }
        if ($anchor === 'fixed' && (! is_string($monthDay) || preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $monthDay) !== 1)) {
            $errors['cap.anchor_month_day'] = 'Give the fixed start as MM-DD, e.g. "01-01".';
        }

        $calc = null;
        $amount = null;
        $feeCap = null;
        if ($postCap !== null) {
            $fee = is_array($postCap) ? ($postCap['fee'] ?? null) : null;
            $calc = is_array($fee) ? (string) ($fee['calc'] ?? '') : '';
            $raw = is_array($fee) ? ($fee['amount'] ?? null) : null;
            $valid = match ($calc) {
                'flat' => is_int($raw) && $raw >= 0,
                'percent' => Percent::isValid($raw),
                default => false,
            };
            if (! $valid) {
                $errors['post_cap.fee'] = 'Use calc "flat" with an amount in cents, or "percent" with a percent string.';
            }
            $amount = $valid ? (string) $raw : null;

            $feeCap = is_array($postCap) ? ($postCap['fee_cap_cents'] ?? null) : null;
            if ($feeCap !== null && (! is_int($feeCap) || $feeCap < 0)) {
                $errors['post_cap.fee_cap_cents'] = 'The post-cap fee cap is in cents.';
                $feeCap = null;
            }
        }

        return new self($cap['amount_cents'], $anchor, is_string($monthDay) ? $monthDay : null, $calc !== '' ? $calc : null, $amount, $feeCap);
    }
}
