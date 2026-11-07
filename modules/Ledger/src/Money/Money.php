<?php

declare(strict_types=1);

namespace Modules\Ledger\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Modules\Ledger\Exceptions\CurrencyMismatch;

/**
 * An immutable amount of money in integer minor units (cents) and an ISO currency.
 *
 * Rules (specs/finance/01):
 * - never a float; all arithmetic is exact;
 * - multiplication rounds half-even to the cent;
 * - allocate() splits an amount so the parts always add up exactly to the original.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(
        public int $cents,
        public string $currency,
    ) {}

    public static function of(int $cents, string $currency = 'USD'): self
    {
        return new self($cents, self::assertCurrency($currency));
    }

    public static function zero(string $currency = 'USD'): self
    {
        return self::of(0, $currency);
    }

    /**
     * Parse a decimal string such as "1234.56" or "-0.5". More than two decimals are rejected.
     */
    public static function fromDecimal(string $amount, string $currency = 'USD'): self
    {
        if (preg_match('/^-?\d+(\.\d{1,2})?$/', trim($amount)) !== 1) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $cents = BigDecimal::of(trim($amount))->multipliedBy(100)->toScale(0)->toInt();

        return self::of($cents, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->cents, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->cents), $this->currency);
    }

    /**
     * Multiply by an exact decimal ratio given as a string (e.g. "0.9", "1.075"), rounding half-even.
     */
    public function multiply(string $ratio): self
    {
        $result = BigDecimal::of($this->cents)
            ->multipliedBy(BigDecimal::of($ratio))
            ->toScale(0, RoundingMode::HalfEven);

        return new self($result->toInt(), $this->currency);
    }

    /**
     * A percentage of this amount, e.g. percentage("12.5") for 12.5%, rounding half-even.
     */
    public function percentage(string $percent): self
    {
        $ratio = BigDecimal::of($percent)->dividedBy(100, 10, RoundingMode::Unnecessary);

        return $this->multiply((string) $ratio);
    }

    /**
     * Split this amount by the given non-negative ratios using the largest-remainder method,
     * so the parts always sum exactly to this amount. Keys of $ratios are preserved.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int|string>  $ratios  integers or decimal strings, e.g. ['agent' => '90', 'company' => '10']
     * @return array<TKey, self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('At least one ratio is required.');
        }

        $parsed = [];
        $sum = BigDecimal::zero();

        foreach ($ratios as $key => $ratio) {
            $value = BigDecimal::of((string) $ratio);
            if ($value->isNegative()) {
                throw new InvalidArgumentException('Ratios cannot be negative.');
            }
            $parsed[$key] = $value;
            $sum = $sum->plus($value);
        }

        if ($sum->isZero()) {
            throw new InvalidArgumentException('Ratios must not all be zero.');
        }

        $total = BigInteger::of(abs($this->cents));
        $sign = $this->cents < 0 ? -1 : 1;

        $shares = [];
        $remainders = [];
        $allocated = BigInteger::zero();
        $order = 0;

        foreach ($parsed as $key => $ratio) {
            $exact = BigDecimal::of($total)->multipliedBy($ratio)->dividedBy($sum, 20, RoundingMode::Down);
            $floor = $exact->toScale(0, RoundingMode::Down)->toBigInteger();
            $shares[$key] = $floor;
            $remainders[] = ['key' => $key, 'fraction' => $exact->minus($floor), 'order' => $order++];
            $allocated = $allocated->plus($floor);
        }

        // Hand out the leftover cents to the largest fractional remainders (ties: first ratio first).
        usort($remainders, function (array $a, array $b): int {
            $byFraction = $b['fraction']->compareTo($a['fraction']);

            return $byFraction !== 0 ? $byFraction : $a['order'] <=> $b['order'];
        });

        $leftover = $total->minus($allocated)->toInt();
        for ($i = 0; $i < $leftover; $i++) {
            $key = $remainders[$i]['key'];
            $shares[$key] = $shares[$key]->plus(1);
        }

        $result = [];
        foreach ($shares as $key => $share) {
            $result[$key] = new self($sign * $share->toInt(), $this->currency);
        }

        return $result;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->cents === $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents < $other->cents;
    }

    /**
     * Exact decimal string, e.g. "1234.56".
     */
    public function toDecimal(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->cents, 2);
    }

    /**
     * Display format, e.g. "$1,234.56" or "-$1,234.56" (USD; other currencies use the code).
     */
    public function format(): string
    {
        $absolute = abs($this->cents);
        $whole = number_format(intdiv($absolute, 100));
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        $symbol = $this->currency === 'USD' ? '$' : $this->currency.' ';

        return ($this->cents < 0 ? '-' : '').$symbol.$whole.'.'.$fraction;
    }

    /**
     * @return array{cents: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['cents' => $this->cents, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    private static function assertCurrency(string $currency): string
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency code [{$currency}].");
        }

        return $currency;
    }
}
