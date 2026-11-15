<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Render;

/** For tests: a plain image of the right size with some detail, and the pages it was asked to draw. */
final class FakeRenderer implements StudioRenderer
{
    /** @var list<string> */
    public array $pages = [];

    public bool $blank = false;

    public function render(string $html, int $width, int $height): string
    {
        $this->pages[] = $html;
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        if (! $this->blank) {
            for ($y = 0; $y < $height; $y += 40) {
                imagefilledrectangle($img, 0, $y, $width, $y + 19, (int) imagecolorallocate($img, 201, 162, 39));
            }
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
