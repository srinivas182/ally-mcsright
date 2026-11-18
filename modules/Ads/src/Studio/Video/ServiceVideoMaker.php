<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Video;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;

/**
 * Makes videos with the renderer HQ chose in Outside services → Video intros, outros and reels (MK3, C22):
 * RightAlly's own renderer (recommended; ffmpeg on our workers), Shotstack or Creatomate. The frames, music and
 * voice-over are put on our storage for the few minutes the provider needs, then removed. Shotstack's Test mode uses
 * its free "stage" environment (watermarked). If the provider fails, our own renderer makes the video instead.
 */
final class ServiceVideoMaker implements VideoMaker
{
    /** How long to wait for a provider (seconds, checking every 5). */
    private const WAIT = 300;

    public function __construct(private readonly VideoMaker $own) {}

    public function make(array $frames, array $seconds, ?string $music, string $out, int $width = 1080, int $height = 1920, ?string $voice = null): string
    {
        $tenants = app(TenantContext::class);
        $service = app(OutsideServices::class)->active('video', $tenants->check() ? $tenants->id() : null);
        if ($service === null || ! in_array($service['provider'], ['shotstack', 'creatomate'], true)) {
            return $this->own->make($frames, $seconds, $music, $out, $width, $height, $voice);
        }
        $disk = Storage::disk('public');
        $dir = 'video-work/'.Str::lower((string) Str::ulid());
        try {
            $urls = [];
            foreach ($frames as $i => $f) {
                $disk->put($dir.'/'.sprintf('%03d', $i).'.png', (string) file_get_contents($f), ['visibility' => 'public']);
                $urls[] = $disk->url($dir.'/'.sprintf('%03d', $i).'.png');
            }
            $musicUrl = $music !== null ? $this->put($disk, $dir.'/music.mp3', $music) : null;
            $voiceUrl = $voice !== null ? $this->put($disk, $dir.'/voice.mp3', $voice) : null;
            $key = (string) ($service['keys']['api_key'] ?? '');
            $url = $service['provider'] === 'shotstack'
                ? $this->shotstack($key, $service['mode'] !== 'live', $urls, $seconds, $musicUrl, $voiceUrl, $width, $height)
                : $this->creatomate($key, $urls, $seconds, $musicUrl, $voiceUrl, $width, $height);
            $video = Http::timeout(120)->get($url);
            if ($video->failed() || $video->body() === '') {
                throw new RuntimeException('The video could not be downloaded from the provider.');
            }
            file_put_contents($out, $video->body());

            return $out;
        } catch (\Throwable $e) {
            report($e);

            return $this->own->make($frames, $seconds, $music, $out, $width, $height, $voice); // our own renderer steps in
        } finally {
            $disk->deleteDirectory($dir);
        }
    }

    /**
     * @param  list<string>  $urls
     * @param  list<float>  $seconds
     */
    private function shotstack(string $key, bool $stage, array $urls, array $seconds, ?string $music, ?string $voice, int $w, int $h): string
    {
        $base = 'https://api.shotstack.io/edit/'.($stage ? 'stage' : 'v1');
        $clips = [];
        $start = 0.0;
        foreach ($urls as $i => $u) {
            $clips[] = ['asset' => ['type' => 'image', 'src' => $u], 'start' => round($start, 2), 'length' => round($seconds[$i], 2),
                'effect' => $i % 2 === 0 ? 'zoomIn' : 'zoomOut', 'transition' => ['in' => 'fade', 'out' => 'fade']];
            $start += $seconds[$i] - 0.6;
        }
        $tracks = [['clips' => $clips]];
        if ($voice !== null) {
            $tracks[] = ['clips' => [['asset' => ['type' => 'audio', 'src' => $voice], 'start' => 1.0, 'length' => round(max(1.0, $start - 0.4), 2)]]];
        }
        $timeline = ['tracks' => $tracks] + ($music !== null ? ['soundtrack' => ['src' => $music, 'effect' => 'fadeInFadeOut', 'volume' => $voice !== null ? 0.22 : 1]] : []);
        $r = Http::timeout(30)->acceptJson()->withHeaders(['x-api-key' => $key])->post($base.'/render', ['timeline' => $timeline,
            'output' => ['format' => 'mp4', 'size' => ['width' => $w, 'height' => $h], 'fps' => 30]]);
        $id = (string) $r->json('response.id');
        if ($r->failed() || $id === '') {
            throw new RuntimeException('Shotstack did not start the render ('.$r->status().').');
        }

        return $this->wait(function () use ($base, $key, $id): array {
            $g = Http::timeout(15)->acceptJson()->withHeaders(['x-api-key' => $key])->get($base.'/render/'.rawurlencode($id));
            $status = (string) $g->json('response.status');

            return [$status === 'done' ? (string) $g->json('response.url') : null, $status === 'failed' || $g->failed()];
        });
    }

    /**
     * @param  list<string>  $urls
     * @param  list<float>  $seconds
     */
    private function creatomate(string $key, array $urls, array $seconds, ?string $music, ?string $voice, int $w, int $h): string
    {
        $elements = [];
        foreach ($urls as $i => $u) {
            $elements[] = ['type' => 'image', 'track' => 1, 'source' => $u, 'duration' => round($seconds[$i], 2),
                'animations' => [['time' => 'start', 'duration' => 0.6, 'transition' => true, 'type' => 'fade'], ['type' => 'scale', 'start_scale' => $i % 2 === 0 ? '100%' : '107%',
                    'end_scale' => $i % 2 === 0 ? '107%' : '100%', 'easing' => 'linear']]];
        }
        if ($music !== null) {
            $elements[] = ['type' => 'audio', 'track' => 2, 'time' => 0, 'source' => $music, 'volume' => $voice !== null ? '22%' : '100%', 'audio_fade_out' => 1.5];
        }
        if ($voice !== null) {
            $elements[] = ['type' => 'audio', 'track' => 3, 'time' => 1, 'source' => $voice];
        }
        $r = Http::timeout(30)->acceptJson()->withToken($key)->post('https://api.creatomate.com/v1/renders', ['source' => ['output_format' => 'mp4', 'width' => $w, 'height' => $h,
            'frame_rate' => 30, 'elements' => $elements]]);
        $id = (string) ($r->json('0.id') ?? $r->json('id') ?? '');
        if ($r->failed() || $id === '') {
            throw new RuntimeException('Creatomate did not start the render ('.$r->status().').');
        }

        return $this->wait(function () use ($key, $id): array {
            $g = Http::timeout(15)->acceptJson()->withToken($key)->get('https://api.creatomate.com/v1/renders/'.rawurlencode($id));
            $status = (string) $g->json('status');

            return [$status === 'succeeded' ? (string) $g->json('url') : null, $status === 'failed' || $g->failed()];
        });
    }

    private function put(Filesystem $disk, string $path, string $file): string
    {
        $disk->put($path, (string) file_get_contents($file), ['visibility' => 'public']);

        return Storage::disk('public')->url($path);
    }

    /** @param  callable(): array{0: string|null, 1: bool}  $poll */
    private function wait(callable $poll): string
    {
        for ($t = 0; $t < self::WAIT; $t += 5) {
            [$url, $failed] = $poll();
            if ($url !== null && str_starts_with($url, 'https://')) {
                return $url;
            }
            if ($failed) {
                throw new RuntimeException('The video provider could not make this video.');
            }
            Sleep::for(5)->seconds();
        }
        throw new RuntimeException('The video provider took too long.');
    }
}
