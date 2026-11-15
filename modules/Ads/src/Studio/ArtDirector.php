<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

/**
 * The AI art director (specs/grow/13, SA1b): checks every design before anyone sees it. Colours are adjusted until
 * text is readable (WCAG contrast); photos too small or blurry are skipped; designs that can't be made beautiful
 * (no usable photo for a listing design, no photo of the agent for an agent design, words that don't fit) are not
 * offered; and each rendered image is checked not to be blank.
 */
final class ArtDirector
{
    /** Shortest side a photo needs to fill a post without looking soft. */
    public const MIN_PHOTO_PX = 1000;

    /** Below this, a photo is too blurry (variance of the Laplacian on a small grey copy). */
    public const MIN_SHARPNESS = 60.0;

    public const MAX_HEADLINE = 60;

    /**
     * The colours a design uses, from the brand kit and the collection's art direction: a deep or a paper ground,
     * ink, a muted tone, hairline rules and a metallic accent (three tones of the brand colour, for gilded type and
     * rules). Text is always readable: ink aims for 7:1 against the ground, the metal for 3:1.
     *
     * @param  array<string, string>  $colors  primary, accent, ink, paper (the brand kit's; defaults fill gaps)
     * @return array{dark: bool, bg: string, paper: string, fg: string, muted: string, rule: string, metal_light: string, metal: string, metal_deep: string, shade: string, bg_glow: string}
     */
    public function palette(array $colors, string $collection): array
    {
        $colors += BrandKits::DEFAULT_COLORS;
        $dark = $collection === 'luxury_noir';
        $bg = match ($collection) {
            'luxury_noir' => self::luminance($colors['primary']) < 0.03 ? $colors['primary'] : self::mix($colors['primary'], '#0C0B09', 0.85),
            'estate' => '#F2ECE1',
            'coastal' => '#ECE4D6',
            default => '#F3F2EF',
        };
        $paper = $dark ? self::mix($bg, '#FFFFFF', 0.06) : ($collection === 'coastal' ? '#F8F3EA' : '#FBF9F5');
        $fg = $dark ? '#F3EEE5' : ($collection === 'coastal' ? '#1D2A2E' : '#1B1916');
        if (self::contrast($fg, $bg) < 7.0) {
            $fg = self::contrast('#FFFFFF', $bg) >= self::contrast('#111111', $bg) ? '#FFFFFF' : '#111111';
        }
        $accent = $colors['accent'];
        // The metal: lighter and brighter on a deep ground, deeper on paper, always readable as large type.
        $metal = $dark ? self::mix($accent, '#FFFFFF', 0.08) : self::mix($accent, '#000000', 0.18);
        for ($i = 0; $i < 6 && self::contrast($metal, $bg) < 3.0; $i++) {
            $metal = self::mix($metal, $dark ? '#FFFFFF' : '#000000', 0.2);
        }
        [$r, $g, $b] = self::rgb($bg);

        return ['dark' => $dark, 'bg' => $bg, 'paper' => $paper, 'fg' => $fg, 'muted' => self::mix($fg, $bg, 0.42), 'rule' => self::mix($fg, $bg, 0.8),
            'metal_light' => self::mix($metal, '#FFFFFF', $dark ? 0.55 : 0.35), 'metal' => $metal, 'metal_deep' => self::mix($metal, '#000000', $dark ? 0.3 : 0.35),
            'shade' => "rgba({$r},{$g},{$b},0.82)", 'bg_glow' => self::mix($bg, $metal, $dark ? 0.14 : 0.06)];
    }

    /**
     * Whether a photo is good enough for a full post: big enough and sharp.
     *
     * @return string|null why not, or null when it is fine
     */
    public function photoProblem(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return 'unreadable photo';
        }
        if (min(imagesx($img), imagesy($img)) < self::MIN_PHOTO_PX && max(imagesx($img), imagesy($img)) < 1400) {
            return 'photo too small';
        }

        return self::sharpness($img) < self::MIN_SHARPNESS ? 'photo too blurry' : null;
    }

    /**
     * Problems with the words on a design.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public function wordsProblems(array $content): array
    {
        $out = [];
        if (mb_strlen((string) ($content['headline'] ?? '')) > self::MAX_HEADLINE) {
            $out[] = 'The headline is too long for a design: keep it under '.self::MAX_HEADLINE.' characters.';
        }
        if (mb_strlen((string) ($content['body'] ?? '')) > 320) {
            $out[] = 'The text is too long for a design: keep it under 320 characters (the caption can say more).';
        }

        return $out;
    }

    /** Whether a rendered image has real content (not a blank or failed page). */
    public function looksBlank(string $png): bool
    {
        $img = @imagecreatefromstring($png);
        if ($img === false) {
            return true;
        }
        $small = imagescale($img, 64, 64) ?: $img;
        $sum = 0.0;
        $sq = 0.0;
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $c = imagecolorat($small, $x, $y);
                $l = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
                $sum += $l;
                $sq += $l * $l;
            }
        }
        $mean = $sum / 4096;

        return sqrt(max(0.0, $sq / 4096 - $mean * $mean)) < 4.0;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function luminance(string $hex): float
    {
        $f = static fn (int $c): float => ($v = $c / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /** $a mixed toward $b by $t (0..1). */
    public static function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);

        return sprintf('#%02X%02X%02X', (int) round($r1 + ($r2 - $r1) * $t), (int) round($g1 + ($g2 - $g1) * $t), (int) round($b1 + ($b2 - $b1) * $t));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        return strlen($h) === 6 ? [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))] : [0, 0, 0];
    }

    private static function sharpness(\GdImage $img): float
    {
        $w = 256;
        $h = max(1, (int) round(imagesy($img) * $w / max(1, imagesx($img))));
        $s = imagescale($img, $w, $h) ?: $img;
        $grey = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($s, $x, $y);
                $grey[$y][$x] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            }
        }
        $n = 0;
        $sum = 0.0;
        $sq = 0.0;
        for ($y = 1; $y < $h - 1; $y++) {
            for ($x = 1; $x < $w - 1; $x++) {
                $l = $grey[$y - 1][$x] + $grey[$y + 1][$x] + $grey[$y][$x - 1] + $grey[$y][$x + 1] - 4 * $grey[$y][$x];
                $sum += $l;
                $sq += $l * $l;
                $n++;
            }
        }
        if ($n === 0) {
            return 0.0;
        }
        $mean = $sum / $n;

        return $sq / $n - $mean * $mean;
    }
}
