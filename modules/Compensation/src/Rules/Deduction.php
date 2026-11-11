<?php

declare(strict_types=1);

namespace Modules\Compensation\Rules;

/**
 * A fee taken before the split (pre-split: franchise, royalty, brokerage referral) or from the
 * agent's side after it (post-split: transaction, E&O, technology).
 */
final readonly class Deduction
{
    public function __construct(
        public string $code,
        public string $name,
        public string $calc,      // flat | percent
        public string $amount,    // cents for flat, percent for percent
        public string $payableTo, // brokerage | franchisor | vendor
    ) {}

    /**
     * @param  array<string, string>  $errors
     * @return list<self>
     */
    public static function parseList(mixed $data, string $path, array &$errors): array
    {
        if ($data === null) {
            return [];
        }

        if (! is_array($data)) {
            $errors[$path] = 'Deductions must be a list.';

            return [];
        }

        $out = [];
        foreach (array_values($data) as $i => $item) {
            if (! is_array($item)) {
                $errors["{$path}.{$i}"] = 'Each deduction needs a code, name, calc and amount.';

                continue;
            }

            $code = (string) ($item['code'] ?? '');
            $name = (string) ($item['name'] ?? '');
            $calc = (string) ($item['calc'] ?? '');
            $amount = $item['amount'] ?? null;
            $payable = (string) ($item['payable_to'] ?? 'brokerage');

            if (preg_match('/^[a-z0-9_]{2,40}$/', $code) !== 1) {
                $errors["{$path}.{$i}.code"] = 'Use a short code like franchise_fee.';
            }
            if (trim($name) === '') {
                $errors["{$path}.{$i}.name"] = 'Give the deduction a name.';
            }
            if (! in_array($payable, ['brokerage', 'franchisor', 'vendor'], true)) {
                $errors["{$path}.{$i}.payable_to"] = 'Payable to brokerage, franchisor or vendor.';
            }

            $valid = match ($calc) {
                'flat' => is_int($amount) && $amount >= 0,
                'percent' => Percent::isValid($amount),
                default => false,
            };

            if (! $valid) {
                $errors["{$path}.{$i}.amount"] = 'Use calc "flat" with an amount in cents, or "percent" with a percent string.';

                continue;
            }

            $out[] = new self($code, trim($name), $calc, (string) $amount, $payable);
        }

        return $out;
    }
}
