<?php

declare(strict_types=1);

use Modules\Core\Branding\ColorMath;
use Modules\Core\Branding\Oklch;
use Modules\Core\Branding\ThemeBuilder;

/**
 * Parse "oklch(L C H)" produced by the theme builder.
 */
function parseOklch(string $css): Oklch
{
    preg_match('/oklch\(([\d.]+) ([\d.]+) ([\d.]+)\)/', $css, $m);

    return new Oklch((float) $m[1], (float) $m[2], (float) $m[3]);
}

it('gives readable text for any brand colour, in light and dark mode (property test)', function (): void {
    mt_srand(20261110);
    $builder = new ThemeBuilder;

    for ($i = 0; $i < 300; $i++) {
        $hex = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
        $theme = $builder->full($hex);

        foreach (['light' => $theme->light, 'dark' => $theme->dark] as $mode => $vars) {
            $pairs = [
                ['primary', 'primary-foreground'],
                ['background', 'foreground'],
                ['background', 'muted-foreground'],
                ['card', 'card-foreground'],
                ['sidebar', 'sidebar-foreground'],
            ];

            foreach ($pairs as [$bg, $fg]) {
                $ratio = ColorMath::contrast(parseOklch($vars[$bg]), parseOklch($vars[$fg]));
                expect($ratio)->toBeGreaterThanOrEqual(4.5, "{$hex} {$mode}: {$fg} on {$bg} is {$ratio}:1");
            }
        }
    }
});

it('limits the back-office accent to primary and focus colours', function (): void {
    $accent = (new ThemeBuilder)->backOfficeAccent('#B91C1C');

    expect(array_keys($accent->light))->toEqualCanonicalizing([
        'primary', 'primary-foreground', 'ring', 'sidebar-primary', 'sidebar-primary-foreground', 'sidebar-ring',
    ])->and(array_keys($accent->dark))->toEqualCanonicalizing(array_keys($accent->light));
});

it('renders CSS custom property blocks', function (): void {
    $css = (new ThemeBuilder)->backOfficeAccent('#2F4FD8')->css(':root', ':root.dark');

    expect($css)->toContain(':root {')
        ->and($css)->toContain(':root.dark {')
        ->and($css)->toMatch('/--primary: oklch\([\d.]+ [\d.]+ [\d.]+\);/');
});
