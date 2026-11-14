<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteMedia;

/**
 * The website media library (specs/websites/08). Every upload is decoded and re-encoded (so only pixels survive:
 * no scripts, no EXIF location data) to WebP at 400, 800 and 1600 px wide, never upscaled. Alt text is required.
 * An image used on a page, post or the brand kit cannot be deleted.
 */
final class MediaLibrary
{
    public const SIZES = ['thumb' => 400, 'card' => 800, 'hero' => 1600];

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string, SiteMedia|null> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function store(Site $site, UploadedFile $file, string $alt, User $by): SiteMedia
    {
        $alt = mb_substr(trim(strip_tags($alt)), 0, 250);
        if ($alt === '') {
            throw ValidationException::withMessages(['alt' => 'Describe the image for people who cannot see it (and for search engines).']);
        }
        $raw = (string) file_get_contents($file->getRealPath() ?: '');
        $info = @getimagesizefromstring($raw);
        if ($file->getSize() > self::MAX_BYTES || $info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) {
            throw ValidationException::withMessages(['file' => 'Upload a JPEG, PNG or WebP image under 10 MB.']);
        }
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            throw ValidationException::withMessages(['file' => 'That image could not be read.']);
        }
        $ulid = (string) Str::ulid();
        $path = 'sites/'.$this->tenants->id().'/'.$ulid;
        $disk = Storage::disk(self::disk());
        $bytes = 0;
        [$w, $h] = [imagesx($img), imagesy($img)];
        try {
            foreach (self::SIZES as $width) {
                $tw = min($width, $w);
                $th = max(1, (int) round($h * $tw / $w));
                $scaled = imagescale($img, $tw, $th, IMG_BICUBIC);
                if ($scaled === false) {
                    throw ValidationException::withMessages(['file' => 'That image could not be resized.']);
                }
                imagepalettetotruecolor($scaled);
                imagesavealpha($scaled, true);
                ob_start();
                imagewebp($scaled, null, 82);
                $webp = (string) ob_get_clean();
                $disk->put("{$path}-{$width}.webp", $webp, ['visibility' => 'public', 'CacheControl' => 'public, max-age=31536000, immutable', 'ContentType' => 'image/webp']);
                $bytes += strlen($webp);
            }
        } finally {
            unset($img);
        }
        $media = SiteMedia::query()->forceCreate(['ulid' => $ulid, 'site_id' => $site->id, 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'width' => $w, 'height' => $h, 'bytes' => $bytes, 'alt' => $alt, 'uploaded_by' => $by->id]);
        app(AuditLogger::class)->record('sites.media.uploaded', $media);

        return $media;
    }

    public function find(string $ulid): ?SiteMedia
    {
        if (! array_key_exists($ulid, $this->cache)) {
            $this->cache[$ulid] = SiteMedia::query()->where('ulid', $ulid)->first();
        }

        return $this->cache[$ulid];
    }

    public static function urlFor(SiteMedia $media, string $size = 'card'): string
    {
        $file = $media->path.'-'.(self::SIZES[$size] ?? 800).'.webp';
        $cdn = config('sites.media_cdn_url');

        return is_string($cdn) && $cdn !== '' ? rtrim($cdn, '/').'/'.$file : Storage::disk(self::disk())->url($file);
    }

    /** An image value from a section or post: a media id (looked up) or an https link (as is). */
    public function url(?string $value, string $size = 'card'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (str_starts_with($value, 'https://')) {
            return $value;
        }
        $media = $this->find($value);

        return $media !== null ? self::urlFor($media, $size) : null;
    }

    public function alt(?string $value, string $fallback = ''): string
    {
        return $value !== null && ! str_starts_with($value, 'https://') ? ($this->find($value)->alt ?? $fallback) : $fallback;
    }

    /** Where an image is used (pages, drafts, posts, brand kit). */
    public function usage(SiteMedia $media): int
    {
        $db = DB::connection(Connections::cell());
        $like = '%'.$media->ulid.'%';

        return $db->table('site_pages')->where('site_id', $media->site_id)->where(fn ($q) => $q->where('sections', 'like', $like)->orWhere('draft_sections', 'like', $like))->count()
            + $db->table('site_posts')->where('site_id', $media->site_id)->where(fn ($q) => $q->where('cover_media', $media->ulid)->orWhere('body', 'like', $like))->count()
            + $db->table('sites')->where('id', $media->site_id)->where('brand', 'like', $like)->count();
    }

    public function delete(SiteMedia $media): void
    {
        if ($this->usage($media) > 0) {
            throw ValidationException::withMessages(['media' => 'This image is still used on the website. Remove it from those pages first.']);
        }
        Storage::disk(self::disk())->delete(array_map(fn (int $w): string => "{$media->path}-{$w}.webp", array_values(self::SIZES)));
        app(AuditLogger::class)->record('sites.media.deleted', $media, metadata: ['alt' => $media->alt]);
        $media->delete();
        unset($this->cache[$media->ulid]);
    }

    private static function disk(): string
    {
        return (string) config('sites.media_disk', 'public');
    }
}
