<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/**
 * Builds semantic design tokens (the shadcn-style variables every component reads) from a
 * brand colour, for light and dark mode, guaranteeing WCAG 2.2 AA text contrast
 * (4.5:1) for primary buttons, body text and muted text (specs/core/05, specs/websites/01).
 */
final class ThemeBuilder
{
    private const AA = 4.5;

    /** Fixed status colours that do not follow the brand. */
    private const DESTRUCTIVE_LIGHT = 'oklch(0.577 0.245 27.325)';

    private const DESTRUCTIVE_DARK = 'oklch(0.704 0.191 22.216)';

    /** Chart hues chosen to stay distinguishable next to most brand colours. */
    private const CHART_HUES = [41.1, 184.7, 227.4, 84.4, 303.9];

    public function __construct(private readonly PaletteGenerator $palettes = new PaletteGenerator) {}

    /**
     * Full theme: brand and neutral scales plus all semantic tokens. Used for the RightAlly
     * default theme and, later, tenant websites.
     */
    public function full(string $brandHex): Theme
    {
        $brand = $this->palettes->scale($brandHex);
        $neutral = $this->palettes->neutral($brandHex);
        $adjustments = [];
        $white = new Oklch(1.0, 0.0, 0.0);

        [$primaryLight, $primaryLightFg, $note] = $this->onColor($brand[600], $neutral[950], 'light');
        if ($note !== null) {
            $adjustments[] = $note;
        }

        [$primaryDark, $primaryDarkFg, $note] = $this->onColor($brand[400], $neutral[950], 'dark');
        if ($note !== null) {
            $adjustments[] = $note;
        }

        $mutedLight = $this->readableOn($neutral[600], $white, darken: true);
        $mutedDark = $this->readableOn($neutral[400], $neutral[950], darken: false);

        $light = $this->scaleVars('brand', $brand) + $this->scaleVars('neutral', $neutral) + [
            'background' => $white->css(),
            'foreground' => $neutral[950]->css(),
            'card' => $white->css(),
            'card-foreground' => $neutral[950]->css(),
            'popover' => $white->css(),
            'popover-foreground' => $neutral[950]->css(),
            'primary' => $primaryLight->css(),
            'primary-foreground' => $primaryLightFg->css(),
            'secondary' => $neutral[100]->css(),
            'secondary-foreground' => $neutral[900]->css(),
            'muted' => $neutral[100]->css(),
            'muted-foreground' => $mutedLight->css(),
            'accent' => $brand[50]->css(),
            'accent-foreground' => $brand[800]->css(),
            'destructive' => self::DESTRUCTIVE_LIGHT,
            'destructive-foreground' => self::DESTRUCTIVE_LIGHT,
            'border' => $neutral[200]->css(),
            'input' => $neutral[200]->css(),
            'ring' => $brand[500]->css(),
            'sidebar' => $neutral[50]->css(),
            'sidebar-foreground' => $neutral[950]->css(),
            'sidebar-primary' => $primaryLight->css(),
            'sidebar-primary-foreground' => $primaryLightFg->css(),
            'sidebar-accent' => $neutral[100]->css(),
            'sidebar-accent-foreground' => $neutral[900]->css(),
            'sidebar-border' => $neutral[200]->css(),
            'sidebar-ring' => $brand[500]->css(),
        ] + $this->chartVars(0.646, 0.19);

        $dark = $this->scaleVars('brand', $brand) + $this->scaleVars('neutral', $neutral) + [
            'background' => $neutral[950]->css(),
            'foreground' => $neutral[50]->css(),
            'card' => $neutral[900]->css(),
            'card-foreground' => $neutral[50]->css(),
            'popover' => $neutral[900]->css(),
            'popover-foreground' => $neutral[50]->css(),
            'primary' => $primaryDark->css(),
            'primary-foreground' => $primaryDarkFg->css(),
            'secondary' => $neutral[800]->css(),
            'secondary-foreground' => $neutral[50]->css(),
            'muted' => $neutral[800]->css(),
            'muted-foreground' => $mutedDark->css(),
            'accent' => $brand[900]->css(),
            'accent-foreground' => $brand[100]->css(),
            'destructive' => self::DESTRUCTIVE_DARK,
            'destructive-foreground' => self::DESTRUCTIVE_DARK,
            'border' => $neutral[800]->css(),
            'input' => $neutral[800]->css(),
            'ring' => $brand[400]->css(),
            'sidebar' => $neutral[900]->css(),
            'sidebar-foreground' => $neutral[50]->css(),
            'sidebar-primary' => $primaryDark->css(),
            'sidebar-primary-foreground' => $primaryDarkFg->css(),
            'sidebar-accent' => $neutral[800]->css(),
            'sidebar-accent-foreground' => $neutral[50]->css(),
            'sidebar-border' => $neutral[800]->css(),
            'sidebar-ring' => $brand[400]->css(),
        ] + $this->chartVars(0.70, 0.17);

        return new Theme($light, $dark, $adjustments);
    }

    /**
     * Back-office accent only (logo + brand accent): primary, ring and sidebar primary.
     * The rest of the back office stays on RightAlly neutrals for consistent usability.
     */
    public function backOfficeAccent(string $brandHex): Theme
    {
        $full = $this->full($brandHex);
        $keys = ['primary', 'primary-foreground', 'ring', 'sidebar-primary', 'sidebar-primary-foreground', 'sidebar-ring'];

        return new Theme(
            array_intersect_key($full->light, array_flip($keys)),
            array_intersect_key($full->dark, array_flip($keys)),
            $full->adjustments,
        );
    }

    /**
     * Pick white or near-black text for a fill; if neither reaches AA, shift the fill's
     * lightness until white (light mode) or near-black (dark mode) text does.
     *
     * @return array{0: Oklch, 1: Oklch, 2: string|null} [fill, text, adjustment note]
     */
    private function onColor(Oklch $fill, Oklch $dark, string $mode): array
    {
        $white = new Oklch(1.0, 0.0, 0.0);

        if ($mode === 'light' && ColorMath::contrast($fill, $white) >= self::AA) {
            return [$fill, $white, null];
        }
        if (ColorMath::contrast($fill, $dark) >= self::AA) {
            return [$fill, $dark, null];
        }
        if ($mode === 'dark' && ColorMath::contrast($fill, $white) >= self::AA) {
            return [$fill, $white, null];
        }

        $adjusted = $fill;
        $text = $mode === 'light' ? $white : $dark;
        $step = $mode === 'light' ? -0.01 : 0.01;

        for ($i = 0; $i < 60 && ColorMath::contrast($adjusted, $text) < self::AA; $i++) {
            $adjusted = ColorMath::toGamut($adjusted->withLightness($adjusted->l + $step));
        }

        return [$adjusted, $text, "Primary colour ({$mode} mode) was ".($step < 0 ? 'darkened' : 'lightened').' slightly so button text stays readable.'];
    }

    /**
     * Shift a text colour's lightness until it reaches AA contrast on the given background.
     */
    private function readableOn(Oklch $text, Oklch $background, bool $darken): Oklch
    {
        for ($i = 0; $i < 60 && ColorMath::contrast($text, $background) < self::AA; $i++) {
            $text = $text->withLightness($text->l + ($darken ? -0.01 : 0.01));
        }

        return $text;
    }

    /**
     * @param  array<int, Oklch>  $scale
     * @return array<string, string>
     */
    private function scaleVars(string $name, array $scale): array
    {
        $vars = [];
        foreach ($scale as $step => $color) {
            $vars["{$name}-{$step}"] = $color->css();
        }

        return $vars;
    }

    /**
     * @return array<string, string>
     */
    private function chartVars(float $lightness, float $chroma): array
    {
        $vars = [];
        foreach (self::CHART_HUES as $i => $hue) {
            $vars['chart-'.($i + 1)] = ColorMath::toGamut(new Oklch($lightness, $chroma, $hue))->css();
        }

        return $vars;
    }
}
