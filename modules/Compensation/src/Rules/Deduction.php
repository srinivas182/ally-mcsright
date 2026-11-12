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
        public string $chargedTo = 'agent', // agent | brokerage (post-split only: paid from company dollar)
        public bool $countsTowardCap = false,
        public bool $revenueShareEligible = true,
        public ?int $annualCapCents = null, // per agent per calendar year
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
            $charged = (string) ($item['charged_to'] ?? 'agent');
            $annualCap = $item['annual_cap_cents'] ?? null;
            if (! in_array($charged, ['agent', 'brokerage'], true)) {
                $errors["{$path}.{$i}.charged_to"] = 'Charged to the agent or the brokerage.';
            }
            if ($annualCap !== null && (! is_int($annualCap) || $annualCap < 0)) {
                $errors["{$path}.{$i}.annual_cap_cents"] = 'A yearly cap is an amount in cents.';
            }

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

            $out[] = new self($code, trim($name), $calc, (string) $amount, $payable, $charged,
                (bool) ($item['counts_toward_cap'] ?? false), (bool) ($item['revenue_share_eligible'] ?? true), is_int($annualCap) ? $annualCap : null);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'calc' => $this->calc, 'amount' => $this->calc === 'flat' ? (int) $this->amount : $this->amount,
            'payable_to' => $this->payableTo, 'charged_to' => $this->chargedTo, 'counts_toward_cap' => $this->countsTowardCap,
            'revenue_share_eligible' => $this->revenueShareEligible, 'annual_cap_cents' => $this->annualCapCents];
    }
}
