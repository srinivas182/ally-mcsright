<?php

declare(strict_types=1);

use Modules\Ledger\Exceptions\CurrencyMismatch;
use Modules\Ledger\Money\Money;

it('adds, subtracts and negates exactly', function (): void {
    $a = Money::fromDecimal('10.10');
    $b = Money::fromDecimal('0.20');

    expect($a->add($b)->cents)->toBe(1030)
        ->and($a->subtract($b)->cents)->toBe(990)
        ->and($a->negate()->cents)->toBe(-1010)
        ->and(Money::fromDecimal('-0.5')->cents)->toBe(-50);
});

it('refuses to mix currencies', function (): void {
    Money::of(100, 'USD')->add(Money::of(100, 'CAD'));
})->throws(CurrencyMismatch::class);

it('rejects invalid amounts and currencies', function (string $amount): void {
    Money::fromDecimal($amount);
})->throws(InvalidArgumentException::class)->with(['1.234', 'abc', '1,000.00', '']);

it('rounds multiplication half-even', function (int $cents, string $ratio, int $expected): void {
    expect(Money::of($cents)->multiply($ratio)->cents)->toBe($expected);
})->with([
    [5, '0.5', 2],
    [15, '0.5', 8],
    [25, '0.5', 12],
    [-5, '0.5', -2],
    [1000, '0.9', 900],
    [333, '0.333', 111],
]);

it('takes percentages exactly', function (): void {
    expect(Money::of(1050)->percentage('12.5')->cents)->toBe(131)
        ->and(Money::fromDecimal('300000')->percentage('3')->cents)->toBe(900000);
});

it('allocates without losing or inventing a cent', function (): void {
    $parts = Money::fromDecimal('100.00')->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->cents, $parts))->toBe([3334, 3333, 3333]);
});

it('keeps keys and handles decimal ratios and negatives', function (): void {
    $split = Money::fromDecimal('9000.00')->allocate(['agent' => '90', 'company' => '10']);
    $negative = Money::of(-100)->allocate([1, 2]);

    expect($split['agent']->cents)->toBe(810000)
        ->and($split['company']->cents)->toBe(90000)
        ->and($negative[0]->cents + $negative[1]->cents)->toBe(-100);
});

it('always allocates the exact total (property test)', function (): void {
    mt_srand(20261109);

    for ($i = 0; $i < 2000; $i++) {
        $cents = mt_rand(-50_000_000, 50_000_000);
        $count = mt_rand(1, 15);
        $ratios = [];
        for ($j = 0; $j < $count; $j++) {
            $ratios[] = mt_rand(0, 3) === 0 ? (string) (mt_rand(1, 10000) / 100) : mt_rand(1, 100);
        }

        $parts = Money::of($cents)->allocate($ratios);
        $sum = array_sum(array_map(fn (Money $m) => $m->cents, $parts));

        expect($sum)->toBe($cents);

        // Each part is within one cent of its exact share.
        $total = array_sum(array_map('floatval', $ratios));
        foreach ($parts as $k => $part) {
            $exact = $cents * ((float) $ratios[$k] / $total);
            expect(abs($part->cents - $exact))->toBeLessThan(1.0001);
        }
    }
});

it('rejects invalid ratios', function (array $ratios): void {
    Money::of(100)->allocate($ratios);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [[]],
    'all zero' => [[0, 0]],
    'negative' => [[1, -1]],
]);

it('formats for display', function (): void {
    expect(Money::of(123456)->format())->toBe('$1,234.56')
        ->and(Money::of(-5)->format())->toBe('-$0.05')
        ->and(Money::of(100, 'CAD')->format())->toBe('CAD 1.00')
        ->and(Money::of(123456)->toDecimal())->toBe('1234.56')
        ->and(json_encode(Money::of(5)))->toBe('{"cents":5,"currency":"USD"}');
});
