<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

use RuntimeException;

/**
 * Compiles resources/design/tokens.json into resources/css/tokens.css (CSS custom properties
 * for light and dark mode). The generated file is committed; CI checks it is up to date.
 */
final class DesignTokens
{
    public function __construct(private readonly ThemeBuilder $themes = new ThemeBuilder) {}

    public function compile(string $tokensPath): string
    {
        $raw = file_get_contents($tokensPath);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$tokensPath}");
        }

        /** @var array{color: array{brand: string}, font: array<string, string>, radius: array{base: string}, shadow: array<string, string>, motion: array<string, string>, zIndex: array<string, string>} $tokens */
        $tokens = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $theme = $this->themes->full($tokens['color']['brand']);

        $shared = ['radius' => $tokens['radius']['base']];
        foreach ($tokens['font'] as $name => $value) {
            $shared["font-family-{$name}"] = $value;
        }
        foreach ($tokens['shadow'] as $name => $value) {
            $shared["elevation-{$name}"] = $value;
        }
        foreach ($tokens['motion'] as $name => $value) {
            $shared["motion-{$name}"] = $value;
        }
        foreach ($tokens['zIndex'] as $name => $value) {
            $shared["z-{$name}"] = $value;
        }

        $themed = new Theme($shared + $theme->light, $theme->dark);

        return "/*\n * GENERATED FILE — do not edit by hand.\n * Source: resources/design/tokens.json. Regenerate: php artisan design:tokens\n */\n\n"
            .$themed->css()
            ."\n@media (prefers-reduced-motion: reduce) {\n    :root {\n        --motion-duration-fast: 0ms;\n        --motion-duration-base: 0ms;\n        --motion-duration-slow: 0ms;\n    }\n}\n";
    }
}
