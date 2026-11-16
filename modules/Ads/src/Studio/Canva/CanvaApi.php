<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Canva;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\NeedsReconnect;
use RuntimeException;
use Throwable;

/**
 * Canva Connect (specs/grow/13, C7d-3): upload the brand logo and listing photos to the person's Canva, make a
 * design at the right size with the cover photo in it, open it in the Canva editor, and, when they press Return,
 * export the finished pages as PNGs. The return carries a JWT signed by Canva (checked against Canva's keys).
 */
final class CanvaApi
{
    public const BASE = 'https://api.canva.com/rest/v1/';

    /** Canva finishes uploads and exports in the background: check every 2 seconds, for up to a minute. */
    private const POLLS = 30;

    /** @return string the asset id */
    public function upload(string $token, string $bytes, string $name): string
    {
        $r = $this->http($token)->withHeaders(['Asset-Upload-Metadata' => (string) json_encode(['name_base64' => base64_encode(mb_substr($name, 0, 50))])])
            ->withBody($bytes, 'application/octet-stream')->post(self::BASE.'asset-uploads');
        self::check($r);
        $job = (array) $r->json('job', []);
        for ($i = 0; ($job['status'] ?? '') === 'in_progress' && $i < self::POLLS; $i++) {
            Sleep::sleep(2);
            $g = $this->http($token)->get(self::BASE.'asset-uploads/'.rawurlencode((string) ($job['id'] ?? '')));
            self::check($g);
            $job = (array) $g->json('job', []);
        }
        $id = $job['asset']['id'] ?? null;
        if (($job['status'] ?? '') !== 'success' || ! is_string($id)) {
            throw new RuntimeException('Canva could not take the image'.(is_string($job['error']['message'] ?? null) ? ': '.$job['error']['message'] : '.'));
        }

        return $id;
    }

    /** @return array{id: string, edit_url: string} */
    public function create(string $token, int $width, int $height, ?string $assetId, string $title): array
    {
        $r = $this->http($token)->asJson()->post(self::BASE.'designs', array_filter(['type' => 'type_and_asset', 'design_type' => ['type' => 'custom', 'width' => $width, 'height' => $height],
            'asset_id' => $assetId, 'title' => mb_substr($title, 0, 255)]));
        self::check($r);
        $id = $r->json('design.id');
        $url = $r->json('design.urls.edit_url');
        if (! is_string($id) || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Canva did not return the design.');
        }

        return ['id' => $id, 'edit_url' => $url];
    }

    /** @return list<string> one PNG link per page (valid for a short time) */
    public function export(string $token, string $designId): array
    {
        $r = $this->http($token)->asJson()->post(self::BASE.'exports', ['design_id' => $designId, 'format' => ['type' => 'png', 'export_quality' => 'pro']]);
        self::check($r);
        $job = (array) $r->json('job', []);
        for ($i = 0; ($job['status'] ?? '') === 'in_progress' && $i < self::POLLS; $i++) {
            Sleep::sleep(2);
            $g = $this->http($token)->get(self::BASE.'exports/'.rawurlencode((string) ($job['id'] ?? '')));
            self::check($g);
            $job = (array) $g->json('job', []);
        }
        $urls = array_values(array_filter((array) ($job['urls'] ?? []), fn ($u): bool => is_string($u) && str_starts_with($u, 'https://')));
        if (($job['status'] ?? '') !== 'success' || $urls === []) {
            throw new RuntimeException('Canva could not export the design'.(is_string($job['error']['message'] ?? null) ? ': '.$job['error']['message'] : '.'));
        }

        return $urls;
    }

    /**
     * Checks the "return to RightAlly" token Canva adds when the person presses Return in the editor.
     *
     * @return array{design_id: string, correlation_state: string}|null
     */
    public function returned(string $jwt): ?array
    {
        $clientId = (string) ($this->keys()['client_id'] ?? '');
        try {
            $keys = Cache::remember('canva:jwks', now()->addHour(), fn (): array => (array) Http::timeout(10)->acceptJson()->get(self::BASE.'connect/keys')->throw()->json());
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet($keys, 'RS256'));
        } catch (Throwable) {
            return null;
        }
        if ($clientId === '' || ($claims['aud'] ?? null) !== $clientId || ($claims['type'] ?? null) !== 'rti' || ! is_string($claims['design_id'] ?? null)
            || ! is_string($claims['correlation_state'] ?? null)) {
            return null;
        }

        return ['design_id' => $claims['design_id'], 'correlation_state' => $claims['correlation_state']];
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(60);
    }

    /** @return array<string, string> */
    private function keys(): array
    {
        $app = app(AppCatalog::class)->find('canva');

        return $app !== null ? app(AppRegistry::class)->secrets($app) : [];
    }

    private static function check(Response $r): void
    {
        if ($r->successful()) {
            return;
        }
        if ($r->status() === 401) {
            throw new NeedsReconnect('Canva asked you to sign in again. Connect Canva again under Apps.');
        }
        throw new RuntimeException(mb_substr('Canva: '.(string) ($r->json('message') ?? 'error '.$r->status()), 0, 300));
    }
}
