<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/**
 * Generates an 11-step colour scale (50-950) from one brand colour, with perceptually even
 * lightness steps and every step mapped into the sRGB gamut (specs/core/05).
 */
final class PaletteGenerator
{
    /**
     * Step => [lightness, chroma multiplier].
     */
    private const STEPS = [
        50 => [0.975, 0.16],
        100 => [0.945, 0.30],
        200 => [0.890, 0.52],
        300 => [0.810, 0.75],
        400 => [0.715, 0.92],
        500 => [0.635, 1.00],
        600 => [0.555, 1.00],
        700 => [0.475, 0.92],
        800 => [0.400, 0.80],
        900 => [0.330, 0.66],
        950 => [0.255, 0.50],
    ];

    /**
     * @return array<int, Oklch> step => colour
     */
    public function scale(string $hex): array
    {
        $seed = ColorMath::hexToOklch($hex);
        // Very grey seeds keep a gentle tint instead of turning fully grey.
        $baseChroma = max($seed->c, 0.02);

        return $this->build($seed->h, $baseChroma);
    }

    /**
     * A neutral grey scale with a faint tint of the brand hue, so greys feel related to the brand.
     *
     * @return array<int, Oklch>
     */
    public function neutral(string $hex, float $tint = 0.012): array
    {
        $seed = ColorMath::hexToOklch($hex);

        return $this->build($seed->h, $tint, flat: true);
    }

    /**
     * @return array<int, Oklch>
     */
    private function build(float $hue, float $chroma, bool $flat = false): array
    {
        $scale = [];

        foreach (self::STEPS as $step => [$lightness, $multiplier]) {
            $c = $flat ? $chroma : $chroma * $multiplier;
            $scale[$step] = ColorMath::toGamut(new Oklch($lightness, $c, $hue));
        }

        return $scale;
    }
}
