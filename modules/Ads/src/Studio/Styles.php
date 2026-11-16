<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

/**
 * The Ally Studio style library, v3 (specs/grow/13, SA1e): sixteen photo-first looks in the way the leading luxury
 * brokerages post today. The photograph is the hero; the cover carries one short line and a small logo; the price and
 * details move to the last carousel slide and the caption. Each style is a signature layout plus two alternates, its
 * own type pairing (modern serifs with clean grotesks) and grounds, and an accent finish (or the brand colour).
 * Earlier style keys (SA1d's eighteen and SA1b's four) still work through ALIASES.
 *
 * Tokens: dark (deep ground), ground/paper/ink (colours), finish (default accent), display (headline font stack),
 * dw/ds/dt/dl (display weight, style, transform, letter-spacing), sans (labels and details), italic (accent serif),
 * layouts (signature first), slide (inner carousel slides: bleed | framed), grade (CSS photo grade), radius.
 */
final class Styles
{
    public const FAMILIES = ['photo' => 'Photo-first', 'gallery' => 'Gallery and print', 'editorial' => 'Editorial', 'modern' => 'Modern and bold',
        'boutique' => 'Warm and boutique', 'classic' => 'Classic estate'];

    /** @var array<string, array{label: string, hex: string|null}> accent finishes; null hex: the brand colour */
    public const FINISHES = ['gold' => ['label' => 'Gold', 'hex' => '#C9A227'], 'champagne' => ['label' => 'Champagne', 'hex' => '#D2B98F'],
        'platinum' => ['label' => 'Platinum', 'hex' => '#B9BEC4'], 'rose_gold' => ['label' => 'Rose gold', 'hex' => '#C9927F'], 'bronze' => ['label' => 'Bronze', 'hex' => '#A9733F'],
        'azure' => ['label' => 'Azure', 'hex' => '#2F6FA8'], 'terracotta' => ['label' => 'Terracotta', 'hex' => '#B8643A'], 'emerald' => ['label' => 'Emerald', 'hex' => '#2E7D5B'],
        'brand' => ['label' => 'Your brand colour', 'hex' => null], 'mono' => ['label' => 'Black and white', 'hex' => '#111111']];

    /** Earlier style keys => their closest v3 style, so saved brand kits, favourites and designs keep working. */
    public const ALIASES = ['noir_atelier' => 'noir', 'estate_ivory' => 'heritage', 'champagne_soiree' => 'arch', 'heritage_navy' => 'heritage', 'emerald_club' => 'noir',
        'gallery_white' => 'mat', 'monochrome_luxe' => 'cinema', 'stone_linen' => 'diptych', 'architect' => 'index', 'coastal_light' => 'horizon', 'riviera' => 'horizon',
        'desert_modern' => 'arch', 'magazine_cover' => 'monograph', 'metropolitan' => 'swiss', 'editorial_journal' => 'index', 'bold_contemporary' => 'block',
        'ranch_land' => 'heritage', 'farmhouse_modern' => 'collage', 'luxury_noir' => 'noir', 'estate' => 'heritage', 'modern' => 'monograph', 'coastal' => 'horizon'];

    public const DEFAULT = 'gallery';

    private const INSTRUMENT = "'Instrument Serif',serif";

    private const INTER = "'Inter Tight',sans-serif";

    private const FRAUNCES = "'Fraunces',serif";

    private const NEWSREADER = "'Newsreader',serif";

    private const CORMORANT = "'Cormorant Garamond',serif";

    /** @var array<string, array<string, mixed>> */
    public const ALL = [
        'gallery' => ['name' => 'Gallery', 'family' => 'photo', 'about' => 'The photograph, full bleed, and one quiet line. The feed look of the top luxury brokerages.',
            'dark' => true, 'ground' => '#111111', 'paper' => '#1A1A1A', 'ink' => '#F5F2EC', 'finish' => 'champagne', 'display' => self::INSTRUMENT, 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.01em',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['gallery', 'monograph', 'frosted'], 'slide' => 'bleed', 'grade' => 'warm', 'radius' => 0],
        'frosted' => ['name' => 'Glass', 'family' => 'photo', 'about' => 'Photo first, with the details on a frosted-glass card. Modern and calm.',
            'dark' => true, 'ground' => '#151515', 'paper' => '#1E1E1E', 'ink' => '#FFFFFF', 'finish' => 'platinum', 'display' => self::INSTRUMENT, 'dw' => 400, 'ds' => 'italic', 'dt' => 'none', 'dl' => '0',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['frosted', 'gallery', 'seal'], 'slide' => 'bleed', 'grade' => 'clean', 'radius' => 30],
        'mat' => ['name' => 'Passe-partout', 'family' => 'gallery', 'about' => 'An ivory mat around the photograph, like a print in a gallery.',
            'dark' => false, 'ground' => '#F4F0E8', 'paper' => '#FBF9F4', 'ink' => '#2A2622', 'finish' => 'bronze', 'display' => self::NEWSREADER, 'dw' => 300, 'ds' => 'italic', 'dt' => 'none', 'dl' => '0',
            'sans' => self::INTER, 'italic' => self::NEWSREADER, 'layouts' => ['mat', 'diptych', 'horizon'], 'slide' => 'framed', 'grade' => 'warm', 'radius' => 0],
        'monograph' => ['name' => 'Monograph', 'family' => 'editorial', 'about' => 'An oversized editorial headline set into the photograph, like a magazine cover.',
            'dark' => true, 'ground' => '#0F0F0F', 'paper' => '#181818', 'ink' => '#FFFFFF', 'finish' => 'champagne', 'display' => self::INSTRUMENT, 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.035em',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['monograph', 'gallery', 'cinema'], 'slide' => 'bleed', 'grade' => 'rich', 'radius' => 0],
        'swiss' => ['name' => 'Swiss Modern', 'family' => 'modern', 'about' => 'Expanded grotesk and a clean data grid: architectural and confident.',
            'dark' => true, 'ground' => '#141414', 'paper' => '#1C1C1C', 'ink' => '#FFFFFF', 'finish' => 'mono', 'display' => "'Archivo',sans-serif", 'dw' => 800, 'ds' => 'normal', 'dt' => 'uppercase', 'dl' => '-0.02em',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['swiss', 'index', 'block'], 'slide' => 'bleed', 'grade' => 'clean', 'radius' => 0],
        'diptych' => ['name' => 'Diptych', 'family' => 'gallery', 'about' => 'Two photographs side by side, outside and in: an interiors-magazine spread.',
            'dark' => false, 'ground' => '#FBFAF7', 'paper' => '#FFFFFF', 'ink' => '#1E1E1E', 'finish' => 'mono', 'display' => self::INSTRUMENT, 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.01em',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['diptych', 'mat', 'collage'], 'slide' => 'framed', 'grade' => 'clean', 'radius' => 0],
        'cinema' => ['name' => 'Cinema', 'family' => 'editorial', 'about' => 'A letterboxed widescreen frame with film-title type.',
            'dark' => true, 'ground' => '#0B0B0C', 'paper' => '#141416', 'ink' => '#FFFFFF', 'finish' => 'champagne', 'display' => self::INTER, 'dw' => 300, 'ds' => 'normal', 'dt' => 'uppercase', 'dl' => '.42em',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['cinema', 'noir', 'monograph'], 'slide' => 'bleed', 'grade' => 'rich', 'radius' => 0],
        'arch' => ['name' => 'Arch', 'family' => 'boutique', 'about' => 'A soft arch window on warm sand: calm, boutique and very current.',
            'dark' => false, 'ground' => '#E9E1D5', 'paper' => '#F3EDE4', 'ink' => '#3B342C', 'finish' => 'terracotta', 'display' => self::FRAUNCES, 'dw' => 300, 'ds' => 'italic', 'dt' => 'lowercase', 'dl' => '0',
            'sans' => self::INTER, 'italic' => self::FRAUNCES, 'layouts' => ['arch', 'collage', 'horizon'], 'slide' => 'framed', 'grade' => 'warm', 'radius' => 0],
        'noir' => ['name' => 'Noir', 'family' => 'classic', 'about' => 'Black ground, a rounded photo card and warm ivory serif: quiet luxury.',
            'dark' => true, 'ground' => '#0E0D0C', 'paper' => '#171513', 'ink' => '#EDE6DA', 'finish' => 'champagne', 'display' => self::INSTRUMENT, 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '0',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['noir', 'cinema', 'gallery'], 'slide' => 'framed', 'grade' => 'warm', 'radius' => 28],
        'collage' => ['name' => 'Collage', 'family' => 'boutique', 'about' => 'Layered prints with soft shadows: lifestyle, warm and social-native.',
            'dark' => false, 'ground' => '#F3EEE6', 'paper' => '#FFFFFF', 'ink' => '#2F2822', 'finish' => 'bronze', 'display' => self::FRAUNCES, 'dw' => 300, 'ds' => 'italic', 'dt' => 'none', 'dl' => '0',
            'sans' => self::INTER, 'italic' => self::FRAUNCES, 'layouts' => ['collage', 'arch', 'diptych'], 'slide' => 'framed', 'grade' => 'warm', 'radius' => 0],
        'block' => ['name' => 'Bold Block', 'family' => 'modern', 'about' => 'A confident block of your brand colour and big modern numerals.',
            'dark' => false, 'ground' => '#F5F3EF', 'paper' => '#FFFFFF', 'ink' => '#111111', 'finish' => 'brand', 'display' => "'Bricolage Grotesque',sans-serif", 'dw' => 800, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.04em',
            'sans' => "'Bricolage Grotesque',sans-serif", 'italic' => self::INSTRUMENT, 'layouts' => ['block', 'swiss', 'seal'], 'slide' => 'bleed', 'grade' => 'clean', 'radius' => 0],
        'index' => ['name' => 'Index', 'family' => 'editorial', 'about' => 'An architect\'s index: fine rules, numbering and precise small type.',
            'dark' => false, 'ground' => '#EFEEEA', 'paper' => '#F8F7F4', 'ink' => '#1B1B1B', 'finish' => 'mono', 'display' => self::INSTRUMENT, 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.02em',
            'sans' => "'Space Grotesk',sans-serif", 'italic' => self::INSTRUMENT, 'layouts' => ['index', 'swiss', 'mat'], 'slide' => 'framed', 'grade' => 'clean', 'radius' => 0],
        'horizon' => ['name' => 'Horizon', 'family' => 'boutique', 'about' => 'Light and airy: the photo above, an open sky-tone ground below.',
            'dark' => false, 'ground' => '#EEF2F5', 'paper' => '#F7F5F1', 'ink' => '#1E2B36', 'finish' => 'azure', 'display' => self::CORMORANT, 'dw' => 300, 'ds' => 'normal', 'dt' => 'none', 'dl' => '0',
            'sans' => "'Manrope',sans-serif", 'italic' => self::CORMORANT, 'layouts' => ['horizon', 'arch', 'mat'], 'slide' => 'framed', 'grade' => 'bright', 'radius' => 0],
        'seal' => ['name' => 'Seal', 'family' => 'photo', 'about' => 'Full-bleed photograph with a rotating seal badge: modern, a little playful.',
            'dark' => true, 'ground' => '#121212', 'paper' => '#F4EFE6', 'ink' => '#FFFFFF', 'finish' => 'champagne', 'display' => self::INSTRUMENT, 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.01em',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['seal', 'frosted', 'gallery'], 'slide' => 'bleed', 'grade' => 'warm', 'radius' => 0],
        'wide' => ['name' => 'Wide', 'family' => 'modern', 'about' => 'Ultra-wide display type floating over the sky: fashion-brand energy.',
            'dark' => true, 'ground' => '#10141C', 'paper' => '#171C26', 'ink' => '#FFFFFF', 'finish' => 'platinum', 'display' => "'Unbounded',sans-serif", 'dw' => 500, 'ds' => 'normal', 'dt' => 'uppercase', 'dl' => '.04em',
            'sans' => self::INTER, 'italic' => self::INSTRUMENT, 'layouts' => ['wide', 'block', 'gallery'], 'slide' => 'bleed', 'grade' => 'rich', 'radius' => 0],
        'heritage' => ['name' => 'Heritage', 'family' => 'classic', 'about' => 'A classic estate brochure: cream, a fine double rule and old-style serif.',
            'dark' => false, 'ground' => '#F6F1E7', 'paper' => '#FBF8F1', 'ink' => '#2A2520', 'finish' => 'gold', 'display' => self::CORMORANT, 'dw' => 300, 'ds' => 'normal', 'dt' => 'none', 'dl' => '0',
            'sans' => self::INTER, 'italic' => self::NEWSREADER, 'layouts' => ['heritage', 'mat', 'noir'], 'slide' => 'framed', 'grade' => 'warm', 'radius' => 0],
    ];

    /** Photo grades: a gentle CSS grade, then (in the template) a fine sharpen. */
    public const GRADES = ['warm' => 'contrast(1.05) saturate(1.06) brightness(1.02) sepia(.05)', 'clean' => 'contrast(1.05) saturate(1.04) brightness(1.02)',
        'rich' => 'contrast(1.08) saturate(1.08)', 'bright' => 'brightness(1.05) saturate(1.06) contrast(1.02)'];

    /** @return array<string, mixed> a style's tokens (earlier keys work; unknown keys fall back to Gallery) */
    public static function get(?string $key): array
    {
        return self::ALL[self::key($key)];
    }

    public static function key(?string $key): string
    {
        $key = self::ALIASES[$key ?? ''] ?? $key;

        return isset(self::ALL[$key ?? '']) ? (string) $key : self::DEFAULT;
    }

    /** Whether a key names a style (an earlier key counts). */
    public static function known(?string $key): bool
    {
        return isset(self::ALL[self::ALIASES[$key ?? ''] ?? $key ?? '']);
    }

    /** @return array<string, string> key => name */
    public static function names(): array
    {
        return array_map(fn (array $s): string => (string) $s['name'], self::ALL);
    }

    public static function grade(string $style): string
    {
        return self::GRADES[(string) self::get($style)['grade']] ?? self::GRADES['clean'];
    }

    /**
     * Two or three styles that suit someone who likes these ones: their favourite family first, then near neighbours.
     *
     * @param  list<string>  $liked
     * @return list<string>
     */
    public static function match(array $liked): array
    {
        $liked = array_values(array_unique(array_map(fn (string $k): string => self::key($k), $liked)));
        $families = array_count_values(array_map(fn (string $k): string => (string) self::ALL[$k]['family'], $liked));
        arsort($families);
        $top = (string) array_key_first($families);
        $dark = count(array_filter($liked, fn (string $k): bool => (bool) self::ALL[$k]['dark'])) * 2 > count($liked);
        $pool = array_keys(array_filter(self::ALL, fn (array $s): bool => $s['family'] === $top));
        usort($pool, fn (string $a, string $b): int => ((self::ALL[$b]['dark'] === $dark) <=> (self::ALL[$a]['dark'] === $dark)));
        // A family of two still offers three: the nearest look by tone fills the last place.
        $near = array_keys(array_filter(self::ALL, fn (array $s): bool => $s['dark'] === $dark));

        return array_slice(array_values(array_unique([...array_intersect($liked, $pool), ...$pool, ...$liked, ...$near])), 0, 3);
    }
}
