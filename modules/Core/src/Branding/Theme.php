<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/**
 * A generated theme: CSS custom properties for light and dark mode, plus notes on any
 * automatic contrast adjustments (shown to the tenant on the branding page).
 */
final readonly class Theme
{
    /**
     * @param  array<string, string>  $light  variable name (without --) => CSS value
     * @param  array<string, string>  $dark
     * @param  list<string>  $adjustments
     */
    public function __construct(
        public array $light,
        public array $dark,
        public array $adjustments = [],
    ) {}

    public function css(string $lightSelector = ':root', string $darkSelector = '.dark'): string
    {
        return $this->block($lightSelector, $this->light)."\n\n".$this->block($darkSelector, $this->dark)."\n";
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function block(string $selector, array $vars): string
    {
        $lines = array_map(fn (string $name, string $value): string => "    --{$name}: {$value};", array_keys($vars), $vars);

        return $selector." {\n".implode("\n", $lines)."\n}";
    }
}
