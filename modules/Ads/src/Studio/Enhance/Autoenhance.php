<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Enhance;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use RuntimeException;

/**
 * Autoenhance.ai (https://docs.autoenhance.ai), the real-estate photo AI behind Studio's enhancement. RightAlly's
 * own account and key (HQ, app `studio_enhance`), so agents and brokerages never need one. Create the image, upload
 * the bytes, poll until enhanced, download the full-size JPEG; a twilight is a "Day to Dusk" restage of the
 * finished image and comes back as a new image.
 */
final class Autoenhance implements PhotoEnhancer
{
    private const API = 'https://api.autoenhance.ai/v3';

    public function ready(): bool
    {
        $app = app(AppCatalog::class)->find('studio_enhance');

        return $app !== null && app(AppRegistry::class)->ready($app) && $this->key() !== '';
    }

    public function start(string $bytes, string $name): string
    {
        $r = $this->http()->post(self::API.'/images/', ['image_name' => mb_substr($name, 0, 100), 'enhance' => true,
            'restage' => ['sky' => 'LOW_CLOUD', 'grass' => 'GREEN']]);
        $id = (string) ($r->json('image_id') ?? '');
        $upload = (string) ($r->json('upload_url') ?? $r->json('s3PutObjectUrl') ?? '');
        if (! $r->successful() || $id === '' || ! str_starts_with($upload, 'https://')) {
            throw new RuntimeException('Autoenhance did not accept the photo ('.$r->status().').');
        }
        $put = Http::withBody($bytes, 'application/octet-stream')->timeout(120)->put($upload);
        if (! $put->successful()) {
            throw new RuntimeException('Autoenhance upload failed ('.$put->status().').');
        }

        return $id;
    }

    public function twilight(string $ref): string
    {
        $r = $this->http()->post(self::API.'/images/'.rawurlencode($ref).'/process', ['restage' => ['environment' => 'DUSK']]);
        $location = (string) $r->header('Location');
        $id = (string) ($r->json('image_id') ?? (preg_match('#/images/([^/?]+)#', $location, $m) === 1 ? $m[1] : ''));
        if (! $r->successful() || $id === '') {
            throw new RuntimeException('Autoenhance twilight was not accepted ('.$r->status().').');
        }

        return $id;
    }

    public function status(string $ref): string
    {
        $r = $this->http()->get(self::API.'/images/'.rawurlencode($ref));
        if (! $r->successful()) {
            return $r->status() === 404 ? 'failed' : 'processing';
        }
        if ($r->json('error') === true || (is_string($r->json('error')) && $r->json('error') !== '')) {
            return 'failed';
        }

        return $r->json('enhanced') === true || $r->json('status') === 'processed' ? 'ready' : 'processing';
    }

    public function fetch(string $ref): string
    {
        $r = $this->http()->timeout(120)->get(self::API.'/images/'.rawurlencode($ref).'/enhanced', ['preview' => 'false', 'format' => 'jpeg', 'quality' => 90]);
        if (! $r->successful() || ! str_starts_with((string) $r->header('Content-Type'), 'image/')) {
            throw new RuntimeException('Autoenhance download failed ('.$r->status().').');
        }

        return $r->body();
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['x-api-key' => $this->key()])->acceptJson()->timeout(60);
    }

    private function key(): string
    {
        $app = app(AppCatalog::class)->find('studio_enhance');

        return $app === null ? '' : (string) (app(AppRegistry::class)->secrets($app)['api_key'] ?? '');
    }
}
