<?php

declare(strict_types=1);

namespace Modules\ListIq\Staging;

use Illuminate\Support\Facades\Http;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Throwable;

/**
 * Virtual staging through Replicate (https://replicate.com/docs) with the interior-design model version HQ chooses
 * (app `listing_staging`): RightAlly's own token, set in HQ → Apps. The prediction waits for the result.
 */
final class ReplicateStager implements VirtualStager
{
    public const STYLES = ['modern' => 'modern', 'farmhouse' => 'modern farmhouse', 'coastal' => 'light coastal', 'minimal' => 'minimalist Scandinavian', 'classic' => 'classic transitional'];

    public function ready(): bool
    {
        $s = $this->secrets();

        return $s !== null && ($s['api_token'] ?? '') !== '' && ($s['model_version'] ?? '') !== '';
    }

    public function stage(string $publicUrl, string $room, string $style): ?string
    {
        $s = $this->secrets();
        if ($s === null) {
            return null;
        }
        $prompt = 'A professionally staged '.str_replace('_', ' ', $room).' in a '.(self::STYLES[$style] ?? 'modern').' style, realistic furniture and decor, natural light, real estate photo. Keep the walls, windows, floors and ceiling exactly as they are.';
        try {
            $r = Http::withToken((string) $s['api_token'])->withHeaders(['Prefer' => 'wait=60'])->acceptJson()->timeout(90)
                ->post('https://api.replicate.com/v1/predictions', ['version' => (string) $s['model_version'], 'input' => ['image' => $publicUrl, 'prompt' => $prompt]]);
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
        $app = app(AppCatalog::class)->find('listing_staging');

        return $app !== null && app(AppRegistry::class)->ready($app) ? app(AppRegistry::class)->secrets($app) : null;
    }
}
