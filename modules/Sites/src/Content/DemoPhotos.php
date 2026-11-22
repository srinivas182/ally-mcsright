<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Modules\Listings\Samples\SampleHomes;
use Throwable;

/**
 * TP2: demo photos. Every design starts with real, licensed photos (Pexels, chosen by hand: no people, no brands) in
 * its banners, sections and blog posts, like a Wix or Squarespace template, so a new website looks finished. A
 * section refers to one as "demo:{id}". The server downloads each photo once (on deploy) and keeps it in the same sizes
 * as an upload; until then the photo comes from Pexels. The owner sees a "Demo" badge on each one in the editor and
 * replaces them with their own over time.
 */
final class DemoPhotos
{
    public const PREFIX = 'demo:';

    private const DIR = 'demo-photos';

    public function __construct(private readonly Http $http) {}

    public static function isRef(mixed $value): bool
    {
        return is_string($value) && preg_match('/^demo:\d{3,12}$/', $value) === 1 && self::find((int) substr($value, 5)) !== null;
    }

    public static function ref(int $id): string
    {
        return self::PREFIX.$id;
    }

    /** A photo from a group, taken in turn by $n (so neighbouring sections differ). */
    public static function pick(string $group, int $n = 0): string
    {
        $list = SampleHomes::photoGroups()[$group] ?? [];

        return $list === [] ? '' : self::ref((int) $list[$n % count($list)]['id']);
    }

    /** @return array{id: int, image: string, page: string, group: string}|null */
    public static function find(int $id): ?array
    {
        foreach (SampleHomes::photoGroups() as $group => $list) {
            foreach ($list as $p) {
                if ((int) $p['id'] === $id) {
                    return $p + ['group' => (string) $group];
                }
            }
        }

        return null;
    }

    /** The address of a demo photo at a size (thumb, card, hero): our stored copy, or Pexels until it is stored. */
    public static function url(string $ref, string $size = 'card'): ?string
    {
        $id = (int) substr($ref, strlen(self::PREFIX));
        $photo = self::find($id);
        if ($photo === null) {
            return null;
        }
        $width = MediaLibrary::SIZES[$size] ?? 800;
        if (in_array($id, self::stored(), true)) {
            $file = self::DIR."/{$id}-{$width}.webp";
            $cdn = config('sites.media_cdn_url');

            return is_string($cdn) && $cdn !== '' ? rtrim($cdn, '/').'/'.$file : Storage::disk(self::disk())->url($file);
        }

        return (string) preg_replace('/w=\d+/', 'w='.$width, $photo['image']);
    }

    /** What a demo photo shows, for its alt text. */
    public static function alt(string $ref): string
    {
        $group = self::find((int) substr($ref, strlen(self::PREFIX)))['group'] ?? '';

        return match (true) {
            str_starts_with($group, 'exterior') => 'A home for sale', $group === 'living' => 'A bright living room', $group === 'kitchen' => 'A modern kitchen',
            $group === 'bedroom' => 'A calm bedroom', $group === 'bathroom' => 'A modern bathroom', $group === 'dining' => 'A dining room',
            $group === 'backyard' => 'A backyard with a pool', $group === 'neighbourhood' => 'A neighborhood seen from above',
            str_starts_with($group, 'city') => 'The city skyline', default => 'Photo',
        };
    }

    /**
     * Every demo photo for the editor's photo picker, each marked as a demo.
     *
     * @return list<array{ulid: string, url: string, alt: string, demo: true}>
     */
    public static function library(): array
    {
        $out = [];
        foreach (SampleHomes::photoGroups() as $list) {
            foreach ($list as $p) {
                $ref = self::ref((int) $p['id']);
                $out[] = ['ulid' => $ref, 'url' => (string) self::url($ref, 'thumb'), 'alt' => self::alt($ref), 'demo' => true];
            }
        }

        return $out;
    }

    /**
     * Downloads every demo photo not yet stored (only from Pexels), in the media library's sizes.
     *
     * @return array{stored: int, failed: list<int>}
     */
    public function install(): array
    {
        $disk = Storage::disk(self::disk());
        $stored = 0;
        $failed = [];
        foreach (SampleHomes::photoGroups() as $list) {
            foreach ($list as $p) {
                $id = (int) $p['id'];
                if ($disk->exists(self::DIR."/{$id}-".MediaLibrary::SIZES['hero'].'.webp')) {
                    continue;
                }
                try {
                    if (! str_starts_with($p['image'], 'https://images.pexels.com/')) {
                        throw new \RuntimeException('Not a Pexels photo.');
                    }
                    $bytes = $this->http->timeout(30)->withOptions(['allow_redirects' => false])
                        ->get((string) preg_replace('/w=\d+/', 'w=2000', $p['image']))->throw()->body();
                    $img = @imagecreatefromstring($bytes);
                    if ($img === false) {
                        throw new \RuntimeException('Not an image.');
                    }
                    [$w, $h] = [imagesx($img), imagesy($img)];
                    foreach (MediaLibrary::SIZES as $width) {
                        $tw = min($width, $w);
                        $scaled = imagescale($img, $tw, max(1, (int) round($h * $tw / $w)), IMG_BICUBIC);
                        if ($scaled === false) {
                            throw new \RuntimeException('Could not resize.');
                        }
                        ob_start();
                        imagewebp($scaled, null, 82); // re-encoded: no camera data is kept
                        $disk->put(self::DIR."/{$id}-{$width}.webp", (string) ob_get_clean(),
                            ['visibility' => 'public', 'CacheControl' => 'public, max-age=31536000, immutable', 'ContentType' => 'image/webp']);
                    }
                    $stored++;
                } catch (Throwable $e) {
                    report($e);
                    $failed[] = $id;
                }
            }
        }
        Cache::forget('demo-photos:stored');

        return ['stored' => $stored, 'failed' => $failed];
    }

    /** @return list<int> */
    private static function stored(): array
    {
        return Cache::remember('demo-photos:stored', 3600, function (): array {
            $disk = Storage::disk(self::disk());
            $ids = [];
            foreach ($disk->files(self::DIR) as $file) {
                if (preg_match('#/(\d+)-'.MediaLibrary::SIZES['hero'].'\.webp$#', $file, $m) === 1) {
                    $ids[] = (int) $m[1];
                }
            }

            return $ids;
        });
    }

    private static function disk(): string
    {
        return (string) config('sites.media_disk', 'public');
    }
}
