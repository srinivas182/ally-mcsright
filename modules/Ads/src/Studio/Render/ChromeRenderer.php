<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Render;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Renders with headless Chrome on the worker: the page is written to a private temporary folder and screenshotted
 * at the exact size. Designs carry no scripts; fonts are bundled files; images come from RightAlly's own storage.
 */
final class ChromeRenderer implements StudioRenderer
{
    public function render(string $html, int $width, int $height): string
    {
        $dir = sys_get_temp_dir().'/studio-'.Str::random(16);
        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException('Could not make a render folder.');
        }
        try {
            file_put_contents($dir.'/design.html', $html);
            $args = [(string) config('studio.chrome'), '--headless=new', '--disable-gpu', '--hide-scrollbars',
                // No background updates or first-run work: without these a server Chrome can stall the render.
                '--disable-background-networking', '--disable-component-update', '--no-first-run', '--disable-sync', '--mute-audio', '--disable-extensions',
                '--force-device-scale-factor=1', '--window-size='.$width.','.$height, '--virtual-time-budget=8000', '--user-data-dir='.$dir.'/profile',
                '--screenshot='.$dir.'/out.png', 'file://'.$dir.'/design.html'];
            if ((bool) config('studio.no_sandbox')) {
                array_splice($args, 1, 0, ['--no-sandbox']);
            }
            $p = new Process($args, $dir, null, null, (float) config('studio.timeout', 45));
            $p->run();
            $png = is_file($dir.'/out.png') ? (string) file_get_contents($dir.'/out.png') : '';
            if ($png === '') {
                throw new RuntimeException('The design could not be rendered: '.mb_substr($p->getErrorOutput(), -300));
            }

            return $png;
        } finally {
            (new Filesystem)->deleteDirectory($dir);
        }
    }
}
