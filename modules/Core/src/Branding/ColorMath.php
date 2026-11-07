<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

use InvalidArgumentException;

/**
 * Colour conversions (sRGB <-> OKLab/OKLCH, after Björn Ottosson), sRGB gamut mapping
 * and WCAG 2.2 contrast. Pure functions, no state.
 */
final class ColorMath
{
    public static function hexToOklch(string $hex): Oklch
    {
        [$r, $g, $b] = self::hexToLinear($hex);

        return self::linearToOklch($r, $g, $b);
    }

    /**
     * @return array{0: float, 1: float, 2: float} linear sRGB
     */
    public static function hexToLinear(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            throw new InvalidArgumentException("Invalid hex colour [#{$hex}]; use #RRGGBB.");
        }

        $channels = array_map(fn (string $pair): float => hexdec($pair) / 255, str_split($hex, 2));

        return [
            self::toLinear($channels[0]),
            self::toLinear($channels[1]),
            self::toLinear($channels[2]),
        ];
    }

    public static function linearToOklch(float $r, float $g, float $b): Oklch
    {
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        $L = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $c = sqrt($a * $a + $bb * $bb);
        $h = rad2deg(atan2($bb, $a));

        return new Oklch($L, $c, $h < 0 ? $h + 360 : $h);
    }

    /**
     * @return array{0: float, 1: float, 2: float} linear sRGB (may be out of gamut)
     */
    public static function oklchToLinear(Oklch $color): array
    {
        $a = $color->c * cos(deg2rad($color->h));
        $b = $color->c * sin(deg2rad($color->h));

        $l = $color->l + 0.3963377774 * $a + 0.2158037573 * $b;
        $m = $color->l - 0.1055613458 * $a - 0.0638541728 * $b;
        $s = $color->l - 0.0894841775 * $a - 1.2914855480 * $b;

        $l **= 3;
        $m **= 3;
        $s **= 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    public static function inGamut(Oklch $color): bool
    {
        foreach (self::oklchToLinear($color) as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reduce chroma (keeping lightness and hue) until the colour fits in sRGB.
     */
    public static function toGamut(Oklch $color): Oklch
    {
        if (self::inGamut($color)) {
            return $color;
        }

        $low = 0.0;
        $high = $color->c;

        for ($i = 0; $i < 24; $i++) {
            $mid = ($low + $high) / 2;
            self::inGamut($color->withChroma($mid)) ? $low = $mid : $high = $mid;
        }

        return $color->withChroma($low);
    }

    /**
     * WCAG relative luminance of an in-gamut colour.
     */
    public static function luminance(Oklch $color): float
    {
        [$r, $g, $b] = array_map(fn (float $v): float => max(0.0, min(1.0, $v)), self::oklchToLinear($color));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * WCAG contrast ratio between two colours (1 to 21).
     */
    public static function contrast(Oklch $a, Oklch $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function toLinear(float $channel): float
    {
        return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private static function cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }
}
