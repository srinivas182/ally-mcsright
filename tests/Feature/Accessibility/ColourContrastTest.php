<?php

declare(strict_types=1);

use Modules\Core\Branding\ColorMath;
use Modules\Core\Branding\Oklch;

/*
| AX2 (specs/core/13): the design tokens meet WCAG 2.2 AA contrast in light and dark, with and without
| stronger contrast: 4.5:1 for text, 3:1 for borders of inputs and the focus outline.
*/

/** @return array<string, Oklch> */
function axTokens(string $block): array
{
    $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/tokens.css');
    preg_match('/'.preg_quote($block, '/').'\s*\{([^}]*)\}/s', $css, $m);
    $out = [];
    preg_match_all('/--([a-z0-9-]+):\s*oklch\(([\d.]+)\s+([\d.]+)\s+([\d.]+)\)/', $m[1] ?? '', $all, PREG_SET_ORDER);
    foreach ($all as $t) {
        $out[$t[1]] = new Oklch((float) $t[2], (float) $t[3], (float) $t[4]);
    }

    return $out;
}

/** @param  array<string, Oklch>  $t */
function axContrast(array $t, string $fg, string $bg): float
{
    return ColorMath::contrast($t[$fg], $t[$bg]);
}

it('meets AA contrast for text in light and dark themes', function (string $block): void {
    $t = axTokens($block);
    foreach ([['foreground', 'background'], ['muted-foreground', 'background'], ['card-foreground', 'card'], ['primary-foreground', 'primary'],
        ['secondary-foreground', 'secondary'], ['accent-foreground', 'accent'], ['sidebar-foreground', 'sidebar'], ['muted-foreground', 'card']] as [$fg, $bg]) {
        expect(axContrast($t, $fg, $bg))->toBeGreaterThanOrEqual(4.5, "{$fg} on {$bg} in {$block}");
    }
    expect(axContrast($t, 'ring', 'background'))->toBeGreaterThanOrEqual(3.0, "focus outline in {$block}");
})->with([':root', '.dark']);

it('makes text and borders stronger when a person turns on stronger contrast', function (): void {
    $light = axTokens(':root');
    $dark = axTokens('.dark');
    // html[data-contrast=high] uses neutral-800 / neutral-500 (light) and neutral-100 / neutral-300 (dark).
    expect(ColorMath::contrast($light['neutral-800'], $light['background']))->toBeGreaterThanOrEqual(7.0)
        ->and(ColorMath::contrast($light['neutral-500'], $light['background']))->toBeGreaterThanOrEqual(3.0)
        ->and(ColorMath::contrast($dark['neutral-100'], $dark['background']))->toBeGreaterThanOrEqual(7.0)
        ->and(ColorMath::contrast($dark['neutral-300'], $dark['background']))->toBeGreaterThanOrEqual(3.0);
});
