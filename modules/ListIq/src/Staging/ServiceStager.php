<?php

declare(strict_types=1);

namespace Modules\ListIq\Staging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Virtual staging through the provider HQ chose in Outside services → Virtual staging (MD1, specs/listings/02):
 * Collov AI (recommended), REimagineHome or Virtual Staging AI. Falls back to the Replicate app (LI1) when the
 * service is off. The staged copy is always labelled "Virtually staged" in the image and its caption, and the
 * original stays next to it (Photos::step).
 */
final class ServiceStager implements VirtualStager
{
    /** How long to wait for a provider (seconds, checking every 3). */
    private const WAIT = 150;

    /** List-IQ rooms → each provider's room types. */
    private const ROOMS = [
        'collov' => ['living' => 'living room', 'family' => 'living room', 'kitchen' => 'kitchen', 'dining' => 'dining room', 'primary_bed' => 'bedroom', 'bedroom' => 'bedroom',
            'office' => 'home office', 'backyard' => 'outdoor', 'pool' => 'outdoor', 'primary_bath' => 'bathroom', 'bathroom' => 'bathroom', 'laundry' => 'laundry room'],
        'virtualstagingai' => ['living' => 'living', 'family' => 'living', 'kitchen' => 'kitchen', 'dining' => 'dining', 'primary_bed' => 'bed', 'bedroom' => 'bed',
            'office' => 'home_office', 'backyard' => 'outdoor', 'pool' => 'outdoor'],
        'reimaginehome' => ['living' => 'ST-INT-011', 'family' => 'ST-INT-011', 'kitchen' => 'ST-INT-009', 'dining' => 'ST-INT-004', 'primary_bed' => 'ST-INT-003', 'bedroom' => 'ST-INT-003',
            'office' => 'ST-INT-016', 'primary_bath' => 'ST-INT-002', 'bathroom' => 'ST-INT-002', 'laundry' => 'ST-INT-010', 'backyard' => 'ST-EXT-001', 'pool' => 'ST-EXT-004'],
    ];

    /** List-IQ styles (ReplicateStager::STYLES) → each provider's styles. */
    private const STYLES = [
        'collov' => ['modern' => 'modern', 'farmhouse' => 'farmhouse', 'coastal' => 'coastal', 'minimal' => 'scandinavian', 'classic' => 'transitional'],
        'virtualstagingai' => ['modern' => 'modern', 'farmhouse' => 'farmhouse', 'coastal' => 'coastal', 'minimal' => 'scandinavian', 'classic' => 'standard'],
        'reimaginehome' => ['modern' => 'DT-INT-011', 'farmhouse' => 'DT-INT-004', 'coastal' => 'DT-INT-002', 'minimal' => 'DT-INT-010', 'classic' => 'DT-INT-015'],
    ];

    public function __construct(private readonly VirtualStager $fallback) {}

    public function ready(): bool
    {
        return $this->service() !== null || $this->fallback->ready();
    }

    public function stage(string $publicUrl, string $room, string $style): ?string
    {
        $service = $this->service();
        if ($service === null) {
            return $this->fallback->stage($publicUrl, $room, $style);
        }
        $p = $service['provider'];
        $key = (string) ($service['keys']['api_key'] ?? '');
        $roomType = self::ROOMS[$p][$room] ?? array_values(self::ROOMS[$p])[0];
        $styleName = self::STYLES[$p][$style] ?? array_values(self::STYLES[$p])[0];
        try {
            $url = match ($p) {
                'collov' => $this->collov($key, $publicUrl, $roomType, $styleName),
                'virtualstagingai' => $this->virtualStagingAi($key, $publicUrl, $roomType, $styleName),
                'reimaginehome' => $this->reimagineHome($key, $publicUrl, $roomType, $styleName),
                default => null,
            };
            if ($url === null || ! str_starts_with($url, 'https://')) {
                return null;
            }
            $img = Http::timeout(60)->get($url);

            return $img->successful() && str_starts_with((string) $img->header('Content-Type'), 'image/') ? $img->body() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array{provider: string, mode: string, keys: array<string, string>, test_contacts: list<string>, level: string}|null */
    private function service(): ?array
    {
        return app(OutsideServices::class)->active('staging', app(TenantContext::class)->check() ? app(TenantContext::class)->id() : null);
    }

    private function collov(string $key, string $image, string $room, string $style): ?string
    {
        $r = Http::timeout(30)->acceptJson()->withHeaders(['apiKey' => $key])->asMultipart()
            ->post('https://api.collov.ai/flair/enterpriseApi/vst/generateImgOnCommon', ['uploadUrl' => $image, 'roomType' => $room, 'style' => $style]);
        $uuid = (string) $r->json('data.uuid');
        if ($r->failed() || $uuid === '') {
            return null;
        }

        return $this->wait(function () use ($key, $uuid): array {
            $g = Http::timeout(15)->acceptJson()->withHeaders(['apiKey' => $key])->get('https://api.collov.ai/flair/enterpriseApi/vst/getRecord', ['uuid' => $uuid]);
            $status = (string) $g->json('data.status');
            $url = (string) ($g->json('data.aiGenerateRecord.generateUrl') ?? $g->json('data.generateRecordList.0.generateUrl') ?? '');

            return [$status === 'SUCCESS' ? $url : null, $status === 'FAILED' || $g->failed()];
        });
    }

    private function virtualStagingAi(string $key, string $image, string $room, string $style): ?string
    {
        $r = Http::timeout(30)->acceptJson()->withHeaders(['Authorization' => 'Api-Key '.$key])->post('https://api.virtualstagingai.app/v2/renders', [
            'config' => ['type' => 'staging', 'add_furniture' => ['room_type' => $room, 'style' => $style], 'add_virtually_staged_watermark' => false],
            'image_url' => $image, 'variation_count' => 1, 'wait_for_completion' => false]);
        $id = (string) $r->json('id');
        if ($r->failed() || $id === '') {
            return null;
        }

        return $this->wait(function () use ($key, $id): array {
            $g = Http::timeout(15)->acceptJson()->withHeaders(['Authorization' => 'Api-Key '.$key])->get('https://api.virtualstagingai.app/v2/renders/'.rawurlencode($id), ['include_variations' => 'true']);
            $v = (array) $g->json('variations.items.0', []);
            $result = $v['result'] ?? null;
            $url = is_array($result) ? (string) ($result['url'] ?? ($result[0]['url'] ?? '')) : '';

            return [($v['status'] ?? '') === 'done' ? $url : null, ($v['status'] ?? '') === 'error' || $g->failed()];
        });
    }

    /** REimagineHome stages through masks: find the furnishing areas, then furnish them. */
    private function reimagineHome(string $key, string $image, string $space, string $theme): ?string
    {
        $h = fn () => Http::timeout(30)->acceptJson()->withHeaders(['api-key' => $key]);
        $m = $h()->post('https://api.reimaginehome.ai/v1/create_mask', ['image_url' => $image]);
        $maskJob = (string) $m->json('data.job_id', (string) $m->json('job_id'));
        if ($m->failed() || $maskJob === '') {
            return null;
        }
        $masks = $this->wait(function () use ($h, $maskJob): array {
            $g = $h()->get('https://api.reimaginehome.ai/v1/create_mask/'.rawurlencode($maskJob));
            $status = (string) $g->json('data.job_status', (string) $g->json('job_status'));
            $urls = array_values(array_filter(array_map(fn ($x): string => is_array($x) && ($x['category'] ?? '') === 'furnishing' ? (string) ($x['url'] ?? '') : '',
                (array) $g->json('data.masks', (array) $g->json('masks', []))), fn (string $u): bool => $u !== ''));

            return [$status === 'done' ? (string) json_encode($urls) : null, $status === 'error' || $g->failed()];
        });
        $urls = $masks !== null ? (array) json_decode($masks, true) : [];
        if ($urls === []) {
            return null;
        }
        $r = $h()->post('https://api.reimaginehome.ai/v1/generate_image', ['image_url' => $image, 'mask_urls' => $urls, 'mask_category' => 'furnishing',
            'space_type' => $space, 'design_theme' => $theme, 'color_preference' => 'Neutral', 'generation_count' => 1]);
        $job = (string) $r->json('data.job_id', (string) $r->json('job_id'));
        if ($r->failed() || $job === '') {
            return null;
        }

        return $this->wait(function () use ($h, $job): array {
            $g = $h()->get('https://api.reimaginehome.ai/v1/generate_image/'.rawurlencode($job));
            $status = (string) $g->json('data.job_status', (string) $g->json('job_status'));

            return [$status === 'done' ? (string) ($g->json('data.generated_images.0') ?? $g->json('generated_images.0') ?? '') : null, $status === 'error' || $g->failed()];
        });
    }

    /**
     * Asks every 3 seconds until done (a value), failed, or out of time.
     *
     * @param  callable(): array{0: string|null, 1: bool}  $poll
     */
    private function wait(callable $poll): ?string
    {
        for ($t = 0; $t < self::WAIT; $t += 3) {
            [$value, $failed] = $poll();
            if ($value !== null) {
                return $value;
            }
            if ($failed) {
                return null;
            }
            Sleep::for(3)->seconds();
        }

        return null;
    }
}
