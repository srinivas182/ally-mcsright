<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Enhance;

use Illuminate\Support\Facades\Http;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Throwable;

/**
 * AI upscaling through Replicate (https://replicate.com/docs), with the Real-ESRGAN model version HQ chooses (app
 * `studio_upscale`): RightAlly's own token. The prediction waits for the result, then the output image is fetched.
 */
final class Replicate implements PhotoUpscaler
{
    public function ready(): bool
    {
        $s = $this->secrets();

        return $s !== null && ($s['api_token'] ?? '') !== '' && ($s['model_version'] ?? '') !== '';
    }

    public function upscale(string $publicUrl): ?string
    {
        $s = $this->secrets();
        if ($s === null) {
            return null;
        }
        try {
            $r = Http::withToken((string) $s['api_token'])->withHeaders(['Prefer' => 'wait=60'])->acceptJson()->timeout(90)
                ->post('https://api.replicate.com/v1/predictions', ['version' => (string) $s['model_version'], 'input' => ['image' => $publicUrl, 'scale' => 2, 'face_enhance' => false]]);
            $out = $r->json('output');
            $url = is_string($out) ? $out : (is_array($out) && is_string($out[0] ?? null) ? $out[0] : '');
            if (! $r->successful() || $r->json('status') === 'failed' || ! str_starts_with($url, 'https://')) {
                return null;
            }
            $img = Http::timeout(90)->get($url);

            return $img->successful() && str_starts_with((string) $img->header('Content-Type'), 'image/') ? $img->body() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function secrets(): ?array
    {
        $app = app(AppCatalog::class)->find('studio_upscale');

        return $app !== null && app(AppRegistry::class)->ready($app) ? app(AppRegistry::class)->secrets($app) : null;
    }
}
