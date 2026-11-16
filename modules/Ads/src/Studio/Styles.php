<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

/**
 * The Ally Studio style library (specs/grow/13, SA1d): eighteen complete looks, each with its own type pairing,
 * grounds, ornaments, photo grading and three signature listing layouts, plus a statement layout for words,
 * numbers, quotes and the agent. Every style takes the brand's photo, headshot and logo, and a finish (the metal or
 * colour of its accents). Old collection keys still work.
 *
 * Token meanings: dark (deep ground), ground/paper/ink (colours), finish (default accent), display/serif/caps/sans/
 * script/italic (font stacks), dw/ds/dt/dl (display weight, style, transform, letter-spacing), ct (caps tracking),
 * layouts (three listing layouts), ornament, grade (CSS filter for photos), radius (photo corners).
 */
final class Styles
{
    public const FAMILIES = ['classic' => 'Classic luxury', 'minimal' => 'Modern minimal', 'resort' => 'Coastal and resort', 'editorial' => 'Editorial and urban',
        'bold' => 'Bold', 'country' => 'Country and land'];

    /** @var array<string, array{label: string, hex: string|null}> accent finishes; null hex: the brand colour */
    public const FINISHES = ['gold' => ['label' => 'Gold', 'hex' => '#C9A227'], 'champagne' => ['label' => 'Champagne', 'hex' => '#D2B98F'],
        'platinum' => ['label' => 'Platinum', 'hex' => '#B9BEC4'], 'rose_gold' => ['label' => 'Rose gold', 'hex' => '#C9927F'], 'bronze' => ['label' => 'Bronze', 'hex' => '#A9733F'],
        'azure' => ['label' => 'Azure', 'hex' => '#2F6FA8'], 'terracotta' => ['label' => 'Terracotta', 'hex' => '#B8643A'], 'emerald' => ['label' => 'Emerald', 'hex' => '#2E7D5B'],
        'brand' => ['label' => 'Your brand colour', 'hex' => null], 'mono' => ['label' => 'Black and white', 'hex' => '#111111']];

    public const ALIASES = ['luxury_noir' => 'noir_atelier', 'estate' => 'estate_ivory', 'modern' => 'magazine_cover', 'coastal' => 'coastal_light'];

    private const CORMORANT = "'Cormorant Garamond',serif";

    private const BODONI = "'Bodoni Moda',serif";

    private const CINZEL = "'Cinzel',serif";

    private const JOST = "'Jost',sans-serif";

    private const PINYON = "'Pinyon Script',cursive";

    /** @var array<string, array<string, mixed>> */
    public const ALL = [
        'noir_atelier' => ['name' => 'Noir Atelier', 'family' => 'classic', 'about' => 'Black and gold, cinematic and confident.', 'dark' => true,
            'ground' => '#0C0B09', 'paper' => '#16140F', 'ink' => '#F3EEE5', 'finish' => 'gold', 'display' => self::CORMORANT, 'dw' => 300, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.005em',
            'serif' => self::BODONI, 'caps' => self::CINZEL, 'ct' => '.42em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => self::CORMORANT,
            'layouts' => ['cover', 'cinema', 'gallery'], 'ornament' => 'rules', 'grade' => 'contrast(1.06) saturate(1.04)', 'radius' => 0],
        'estate_ivory' => ['name' => 'Estate Ivory', 'family' => 'classic', 'about' => 'Ivory, a fine double frame and classical serif.', 'dark' => false,
            'ground' => '#F2ECE1', 'paper' => '#FBF8F2', 'ink' => '#1B1916', 'finish' => 'gold', 'display' => self::CORMORANT, 'dw' => 300, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.005em',
            'serif' => self::BODONI, 'caps' => self::CINZEL, 'ct' => '.42em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => self::CORMORANT,
            'layouts' => ['frame', 'gallery', 'arch'], 'ornament' => 'diamond', 'grade' => 'contrast(1.05) saturate(1.02)', 'radius' => 0],
        'champagne_soiree' => ['name' => 'Champagne Soirée', 'family' => 'classic', 'about' => 'Soft champagne and script accents: evening elegance.', 'dark' => false,
            'ground' => '#F0E6DA', 'paper' => '#FAF4EC', 'ink' => '#2A2320', 'finish' => 'champagne', 'display' => "'Playfair Display',serif", 'dw' => 400, 'ds' => 'italic', 'dt' => 'none', 'dl' => '0',
            'serif' => "'Playfair Display',serif", 'caps' => "'Tenor Sans',sans-serif", 'ct' => '.36em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => "'Playfair Display',serif",
            'layouts' => ['cover', 'arch', 'gallery'], 'ornament' => 'dots', 'grade' => 'brightness(1.05) saturate(.94) contrast(1.02)', 'radius' => 0],
        'heritage_navy' => ['name' => 'Heritage Navy', 'family' => 'classic', 'about' => 'Navy and brass with a crest: established prestige.', 'dark' => true,
            'ground' => '#0E1A2B', 'paper' => '#16243A', 'ink' => '#F2EEE6', 'finish' => 'bronze', 'display' => "'Libre Caslon Text',serif", 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '0',
            'serif' => "'Libre Caslon Text',serif", 'caps' => self::CINZEL, 'ct' => '.4em', 'sans' => "'Montserrat',sans-serif", 'script' => self::PINYON, 'italic' => "'Libre Caslon Text',serif",
            'layouts' => ['frame', 'cinema', 'split'], 'ornament' => 'crest', 'grade' => 'contrast(1.06) saturate(.98)', 'radius' => 0],
        'emerald_club' => ['name' => 'Emerald Club', 'family' => 'classic', 'about' => 'Deep green and gold: quiet old-money luxury.', 'dark' => true,
            'ground' => '#0D2A22', 'paper' => '#123529', 'ink' => '#F1EDE3', 'finish' => 'gold', 'display' => "'DM Serif Display',serif", 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '0',
            'serif' => "'DM Serif Display',serif", 'caps' => self::CINZEL, 'ct' => '.4em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => "'DM Serif Display',serif",
            'layouts' => ['frame', 'cover', 'gallery'], 'ornament' => 'rules', 'grade' => 'contrast(1.05) saturate(1.02)', 'radius' => 0],
        'gallery_white' => ['name' => 'Gallery White', 'family' => 'minimal', 'about' => 'All white, black hairlines and vast space: the photograph is the art.', 'dark' => false,
            'ground' => '#FFFFFF', 'paper' => '#FFFFFF', 'ink' => '#0A0A0A', 'finish' => 'mono', 'display' => "'Montserrat',sans-serif", 'dw' => 300, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.02em',
            'serif' => "'Montserrat',sans-serif", 'caps' => "'Montserrat',sans-serif", 'ct' => '.5em', 'sans' => "'Montserrat',sans-serif", 'script' => "'Montserrat',sans-serif", 'italic' => self::CORMORANT,
            'layouts' => ['gallery', 'split', 'arch'], 'ornament' => 'none', 'grade' => 'contrast(1.04) saturate(1.02) brightness(1.02)', 'radius' => 0],
        'monochrome_luxe' => ['name' => 'Monochrome Luxe', 'family' => 'minimal', 'about' => 'Black-and-white photography, platinum and high contrast.', 'dark' => true,
            'ground' => '#0A0A0A', 'paper' => '#141414', 'ink' => '#FFFFFF', 'finish' => 'platinum', 'display' => self::BODONI, 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.01em',
            'serif' => self::BODONI, 'caps' => "'Montserrat',sans-serif", 'ct' => '.52em', 'sans' => "'Montserrat',sans-serif", 'script' => self::PINYON, 'italic' => self::BODONI,
            'layouts' => ['mono', 'cinema', 'cover'], 'ornament' => 'rules', 'grade' => 'grayscale(1) contrast(1.18) brightness(1.02)', 'radius' => 0],
        'stone_linen' => ['name' => 'Stone & Linen', 'family' => 'minimal', 'about' => 'Warm greys and linen: calm, Scandinavian, natural.', 'dark' => false,
            'ground' => '#E8E4DE', 'paper' => '#F4F1EC', 'ink' => '#2B2926', 'finish' => 'bronze', 'display' => self::CORMORANT, 'dw' => 500, 'ds' => 'normal', 'dt' => 'none', 'dl' => '0',
            'serif' => self::CORMORANT, 'caps' => "'Tenor Sans',sans-serif", 'ct' => '.34em', 'sans' => "'DM Sans',sans-serif", 'script' => self::PINYON, 'italic' => self::CORMORANT,
            'layouts' => ['split', 'arch', 'gallery'], 'ornament' => 'none', 'grade' => 'saturate(.86) sepia(.08) contrast(1.03)', 'radius' => 14],
        'architect' => ['name' => 'Architect', 'family' => 'minimal', 'about' => 'Fine grids and blueprint details, for modern design and new builds.', 'dark' => false,
            'ground' => '#F3F4F5', 'paper' => '#FFFFFF', 'ink' => '#15181C', 'finish' => 'brand', 'display' => "'Syne',sans-serif", 'dw' => 600, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.02em',
            'serif' => "'Syne',sans-serif", 'caps' => "'IBM Plex Mono',monospace", 'ct' => '.18em', 'sans' => "'IBM Plex Mono',monospace", 'script' => "'Syne',sans-serif", 'italic' => "'Syne',sans-serif",
            'layouts' => ['blueprint', 'split', 'cover'], 'ornament' => 'grid', 'grade' => 'contrast(1.06) saturate(.96)', 'radius' => 0],
        'coastal_light' => ['name' => 'Coastal Light', 'family' => 'resort', 'about' => 'Sand, sea glass and arches: light, airy, by the water.', 'dark' => false,
            'ground' => '#ECE4D6', 'paper' => '#F8F3EA', 'ink' => '#1D2A2E', 'finish' => 'champagne', 'display' => self::CORMORANT, 'dw' => 300, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.005em',
            'serif' => self::BODONI, 'caps' => self::CINZEL, 'ct' => '.42em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => self::CORMORANT,
            'layouts' => ['arch', 'split', 'cover'], 'ornament' => 'wave', 'grade' => 'brightness(1.04) saturate(1.04)', 'radius' => 26],
        'riviera' => ['name' => 'Riviera', 'family' => 'resort', 'about' => 'Azure and white, sun-washed and Mediterranean.', 'dark' => false,
            'ground' => '#F4F7FA', 'paper' => '#FFFFFF', 'ink' => '#0F2B46', 'finish' => 'azure', 'display' => "'Marcellus',serif", 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '.01em',
            'serif' => "'Marcellus',serif", 'caps' => "'Tenor Sans',sans-serif", 'ct' => '.4em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => self::CORMORANT,
            'layouts' => ['cover', 'arch', 'gallery'], 'ornament' => 'wave', 'grade' => 'brightness(1.06) saturate(1.1) contrast(1.02)', 'radius' => 0],
        'desert_modern' => ['name' => 'Desert Modern', 'family' => 'resort', 'about' => 'Terracotta, clay and sun arches: warm Southwestern modern.', 'dark' => false,
            'ground' => '#EADBC8', 'paper' => '#F5ECE0', 'ink' => '#3A2418', 'finish' => 'terracotta', 'display' => "'DM Serif Display',serif", 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '0',
            'serif' => "'DM Serif Display',serif", 'caps' => "'Tenor Sans',sans-serif", 'ct' => '.36em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => "'DM Serif Display',serif",
            'layouts' => ['arch', 'split', 'cover'], 'ornament' => 'sun', 'grade' => 'sepia(.12) saturate(1.06) contrast(1.03)', 'radius' => 0],
        'magazine_cover' => ['name' => 'Magazine Cover', 'family' => 'editorial', 'about' => 'A fashion-magazine cover with a masthead across the photograph.', 'dark' => false,
            'ground' => '#F3F2EF', 'paper' => '#FBFAF7', 'ink' => '#111111', 'finish' => 'gold', 'display' => self::CORMORANT, 'dw' => 300, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.005em',
            'serif' => self::BODONI, 'caps' => self::CINZEL, 'ct' => '.42em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => self::BODONI,
            'layouts' => ['masthead', 'split', 'gallery'], 'ornament' => 'rules', 'grade' => 'contrast(1.06) saturate(1.04)', 'radius' => 0],
        'metropolitan' => ['name' => 'Metropolitan', 'family' => 'editorial', 'about' => 'Slate and platinum, sharp and urban: condos and city living.', 'dark' => true,
            'ground' => '#14171C', 'paper' => '#1B1F25', 'ink' => '#EEF0F2', 'finish' => 'platinum', 'display' => "'Montserrat',sans-serif", 'dw' => 300, 'ds' => 'normal', 'dt' => 'uppercase', 'dl' => '.08em',
            'serif' => self::BODONI, 'caps' => "'Montserrat',sans-serif", 'ct' => '.5em', 'sans' => "'Montserrat',sans-serif", 'script' => self::BODONI, 'italic' => self::BODONI,
            'layouts' => ['cover', 'cinema', 'split'], 'ornament' => 'grid', 'grade' => 'contrast(1.1) saturate(.9) brightness(.98)', 'radius' => 0],
        'editorial_journal' => ['name' => 'Editorial Journal', 'family' => 'editorial', 'about' => 'A luxury magazine spread: columns, drop caps and fine rules.', 'dark' => false,
            'ground' => '#F4F1EA', 'paper' => '#FBFAF6', 'ink' => '#141414', 'finish' => 'mono', 'display' => "'Libre Caslon Text',serif", 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.01em',
            'serif' => "'Libre Caslon Text',serif", 'caps' => "'Tenor Sans',sans-serif", 'ct' => '.3em', 'sans' => "'Libre Caslon Text',serif", 'script' => "'Libre Caslon Text',serif", 'italic' => "'Libre Caslon Text',serif",
            'layouts' => ['journal', 'frame', 'gallery'], 'ornament' => 'rules', 'grade' => 'contrast(1.05) saturate(.96)', 'radius' => 0],
        'bold_contemporary' => ['name' => 'Bold Contemporary', 'family' => 'bold', 'about' => 'Oversized type and rich colour fields, for a younger audience.', 'dark' => false,
            'ground' => '#F6F1EA', 'paper' => '#FFFFFF', 'ink' => '#111111', 'finish' => 'brand', 'display' => "'Bebas Neue',sans-serif", 'dw' => 400, 'ds' => 'normal', 'dt' => 'uppercase', 'dl' => '.01em',
            'serif' => "'Bebas Neue',sans-serif", 'caps' => "'Syne',sans-serif", 'ct' => '.2em', 'sans' => "'Syne',sans-serif", 'script' => "'Syne',sans-serif", 'italic' => "'Syne',sans-serif",
            'layouts' => ['colorfield', 'cover', 'split'], 'ornament' => 'none', 'grade' => 'contrast(1.08) saturate(1.1)', 'radius' => 0],
        'ranch_land' => ['name' => 'Ranch & Land', 'family' => 'country', 'about' => 'Golden-hour landscapes, bronze and classic serif, for acreage and estates.', 'dark' => false,
            'ground' => '#EEE6D8', 'paper' => '#F8F2E8', 'ink' => '#2B2118', 'finish' => 'bronze', 'display' => "'Libre Baskerville',serif", 'dw' => 400, 'ds' => 'normal', 'dt' => 'none', 'dl' => '0',
            'serif' => "'Libre Baskerville',serif", 'caps' => self::CINZEL, 'ct' => '.4em', 'sans' => self::JOST, 'script' => self::PINYON, 'italic' => self::CORMORANT,
            'layouts' => ['cinema', 'frame', 'gallery'], 'ornament' => 'rules', 'grade' => 'sepia(.14) saturate(1.08) contrast(1.04)', 'radius' => 0],
        'farmhouse_modern' => ['name' => 'Farmhouse Modern', 'family' => 'country', 'about' => 'White oak, charcoal and soft arches: modern farmhouse warmth.', 'dark' => false,
            'ground' => '#F2F0EB', 'paper' => '#FBFAF7', 'ink' => '#1E1E1C', 'finish' => 'mono', 'display' => "'Playfair Display',serif", 'dw' => 600, 'ds' => 'normal', 'dt' => 'none', 'dl' => '-0.01em',
            'serif' => "'Playfair Display',serif", 'caps' => "'Montserrat',sans-serif", 'ct' => '.44em', 'sans' => "'Montserrat',sans-serif", 'script' => self::PINYON, 'italic' => "'Playfair Display',serif",
            'layouts' => ['split', 'arch', 'gallery'], 'ornament' => 'none', 'grade' => 'saturate(.95) contrast(1.04) brightness(1.03)', 'radius' => 18],
    ];

    /** @return array<string, mixed> a style's tokens (old collection keys work; unknown keys fall back to Noir Atelier) */
    public static function get(?string $key): array
    {
        $key = self::ALIASES[$key ?? ''] ?? $key;

        return self::ALL[$key ?? ''] ?? self::ALL['noir_atelier'];
    }

    public static function key(?string $key): string
    {
        $key = self::ALIASES[$key ?? ''] ?? $key;

        return isset(self::ALL[$key ?? '']) ? (string) $key : 'noir_atelier';
    }

    /** @return array<string, string> key => name */
    public static function names(): array
    {
        return array_map(fn (array $s): string => (string) $s['name'], self::ALL);
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

        return array_slice(array_values(array_unique([...array_intersect($liked, $pool), ...$pool, ...$liked])), 0, 3);
    }
}
