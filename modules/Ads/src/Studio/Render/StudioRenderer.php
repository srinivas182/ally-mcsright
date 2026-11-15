<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Render;

/** Turns one Studio design (a self-contained HTML page) into an image (specs/grow/13). */
interface StudioRenderer
{
    /** @return string PNG bytes, exactly $width × $height */
    public function render(string $html, int $width, int $height): string;
}
