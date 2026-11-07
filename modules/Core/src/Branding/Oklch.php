<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/**
 * A colour in OKLCH: perceptual lightness (0-1), chroma (0+) and hue (degrees).
 */
final readonly class Oklch
{
    public function __construct(
        public float $l,
        public float $c,
        public float $h,
    ) {}

    public function withLightness(float $l): self
    {
        return new self(max(0.0, min(1.0, $l)), $this->c, $this->h);
    }

    public function withChroma(float $c): self
    {
        return new self($this->l, max(0.0, $c), $this->h);
    }

    /**
     * CSS value, e.g. "oklch(0.56 0.18 264.1)".
     */
    public function css(): string
    {
        return sprintf('oklch(%s %s %s)', $this->fmt($this->l, 3), $this->fmt($this->c, 3), $this->fmt($this->h, 1));
    }

    private function fmt(float $value, int $decimals): string
    {
        $text = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
