<?php

declare(strict_types=1);

use Modules\Core\Branding\ColorMath;
use Modules\Core\Branding\Oklch;
use Modules\Core\Branding\PaletteGenerator;

it('converts known colours to OKLCH', function (string $hex, float $l, float $c, float $h): void {
    $color = ColorMath::hexToOklch($hex);

    expect($color->l)->toEqualWithDelta($l, 0.002)
        ->and($color->c)->toEqualWithDelta($c, 0.002);
    if ($c > 0.01) {
        expect($color->h)->toEqualWithDelta($h, 0.5);
    }
})->with([
    'red' => ['#ff0000', 0.628, 0.258, 29.2],
    'green' => ['#00ff00', 0.866, 0.295, 142.5],
    'blue' => ['#0000ff', 0.452, 0.313, 264.1],
    'white' => ['#ffffff', 1.0, 0.0, 0.0],
    'black' => ['#000000', 0.0, 0.0, 0.0],
]);

it('round-trips through linear sRGB', function (): void {
    [$r, $g, $b] = ColorMath::hexToLinear('#2F4FD8');
    [$r2, $g2, $b2] = ColorMath::oklchToLinear(ColorMath::linearToOklch($r, $g, $b));

    expect($r2)->toEqualWithDelta($r, 1e-6)
        ->and($g2)->toEqualWithDelta($g, 1e-6)
        ->and($b2)->toEqualWithDelta($b, 1e-6);
});

it('computes WCAG contrast', function (): void {
    $white = ColorMath::hexToOklch('#ffffff');

    expect(ColorMath::contrast($white, ColorMath::hexToOklch('#000000')))->toEqualWithDelta(21.0, 0.01)
        ->and(ColorMath::contrast($white, ColorMath::hexToOklch('#777777')))->toEqualWithDelta(4.48, 0.02)
        ->and(ColorMath::contrast($white, $white))->toEqualWithDelta(1.0, 0.0001);
});

it('maps out-of-gamut colours into sRGB by reducing chroma only', function (): void {
    $vivid = new Oklch(0.7, 0.4, 150.0);
    $mapped = ColorMath::toGamut($vivid);

    expect(ColorMath::inGamut($vivid))->toBeFalse()
        ->and(ColorMath::inGamut($mapped))->toBeTrue()
        ->and($mapped->l)->toBe(0.7)
        ->and($mapped->h)->toBe(150.0)
        ->and($mapped->c)->toBeLessThan(0.4);
});

it('rejects invalid hex colours', function (string $hex): void {
    ColorMath::hexToOklch($hex);
})->throws(InvalidArgumentException::class)->with(['#fff', 'blue', '#12345G', '']);

it('generates an 11-step scale that gets darker and stays in gamut', function (): void {
    $scale = (new PaletteGenerator)->scale('#2F4FD8');

    expect(array_keys($scale))->toBe([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]);

    $previous = 1.1;
    foreach ($scale as $color) {
        expect($color->l)->toBeLessThan($previous)
            ->and(ColorMath::inGamut($color))->toBeTrue();
        $previous = $color->l;
    }
});
