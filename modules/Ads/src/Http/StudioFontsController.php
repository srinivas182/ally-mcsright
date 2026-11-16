<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Ally Studio fonts (SIL OFL, specs/grow/13), served publicly so printers and previews can load them: only the
 * bundled .woff2 files, cached for a year.
 */
final class StudioFontsController
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = dirname(__DIR__, 2).'/resources/fonts/'.$file.'.woff2';
        abort_unless(preg_match('/^[a-z0-9-]{3,80}$/', $file) === 1 && is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'font/woff2', 'Cache-Control' => 'public, max-age=31536000, immutable', 'Access-Control-Allow-Origin' => '*']);
    }
}
