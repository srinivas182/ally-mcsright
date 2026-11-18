<?php

declare(strict_types=1);

namespace Modules\Listings\Media;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Storage;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use RuntimeException;

/**
 * Listing photos (specs/websites/02): the MLS's photo list is mirrored per listing; each photo is downloaded once
 * (and again only when the MLS changes it), checked to be an image, and saved as WebP in three widths on our storage
 * behind the CDN. Failures retry with back-off for 24 hours. Only public https addresses are fetched.
 */
final class Photos
{
    public function __construct(private readonly Http $http) {}

    /**
     * Mirrors the record's Media list. Returns true when photos need fetching.
     *
     * @param  array<string, mixed>  $record
     */
    public function syncList(Listing $listing, array $record): bool
    {
        if (! isset($record['Media']) || ! is_array($record['Media'])) {
            return false; // feeds without expanded Media: photos come from the Media resource (later)
        }
        $keep = [];
        $pending = false;
        foreach (array_values($record['Media']) as $i => $m) {
            if (! is_array($m) || ! is_string($m['MediaURL'] ?? null) || ($m['MediaCategory'] ?? 'Photo') !== 'Photo') {
                continue;
            }
            $key = mb_substr((string) ($m['MediaKey'] ?? md5((string) $m['MediaURL'])), 0, 100);
            $keep[] = $key;
            $modified = is_string($m['MediaModificationTimestamp'] ?? null) ? CarbonImmutable::parse($m['MediaModificationTimestamp'])->utc() : null;
            $row = ListingMedia::query()->where('listing_id', $listing->id)->where('media_key', $key)->first();
            $changed = $row === null || $row->source_url !== $m['MediaURL'] || ($modified !== null && ($row->modification_timestamp === null || ! $row->modification_timestamp->eq($modified)));
            $row ??= new ListingMedia(['listing_id' => $listing->id, 'media_key' => $key]);
            $row->fill(['position' => is_numeric($m['Order'] ?? null) ? (int) $m['Order'] : $i, 'source_url' => mb_substr((string) $m['MediaURL'], 0, 1000),
                'caption' => is_string($m['ShortDescription'] ?? null) ? mb_substr($m['ShortDescription'], 0, 300) : null, 'modification_timestamp' => $modified]);
            if ($changed) {
                // C03: a changed photo is tagged again, and the listing's features gathered again.
                $row->fill(['status' => 'pending', 'attempts' => 0, 'first_attempt_at' => null, 'error' => null, 'room' => null, 'tags' => null, 'alt_text' => null, 'tagged_at' => null]);
                $pending = true;
            }
            $row->save();
        }
        $removed = false;
        foreach (ListingMedia::query()->where('listing_id', $listing->id)->whereNotIn('media_key', $keep)->get() as $gone) {
            $this->deleteFiles($gone);
            $gone->delete();
            $removed = true;
        }
        if (($pending || $removed) && $listing->getAttribute('photos_tagged_at') !== null) {
            $listing->forceFill(['photos_tagged_at' => null])->saveQuietly();
        }
        $this->refreshCover($listing);

        return $pending;
    }

    /** Downloads and stores one photo. Returns false when it should be retried later. */
    public function store(ListingMedia $media, Listing $listing): bool
    {
        $media->forceFill(['attempts' => min(255, $media->attempts + 1), 'first_attempt_at' => $media->first_attempt_at ?? CarbonImmutable::now()])->save();
        try {
            $bytes = $this->download($media->source_url);
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                throw new RuntimeException('Not an image.');
            }
            $w = imagesx($image);
            $h = imagesy($image);
            $base = 'listings/'.$listing->mls_source_id.'/'.$listing->ulid.'/'.substr(hash('sha256', $media->media_key), 0, 16);
            $disk = Storage::disk((string) config('listings.media_disk', 'public'));
            foreach ((array) config('listings.sizes') as $width) {
                $tw = (int) min($width, $w);
                $th = (int) max(1, round($h * $tw / max(1, $w)));
                $copy = imagescale($image, $tw, $th, IMG_BICUBIC);
                if ($copy === false) {
                    throw new RuntimeException('Could not resize.');
                }
                ob_start();
                imagewebp($copy, null, 80);
                $disk->put("{$base}-{$width}.webp", (string) ob_get_clean(), ['visibility' => 'public', 'CacheControl' => 'public, max-age=31536000, immutable']);
                imagedestroy($copy);
            }
            imagedestroy($image);
            $media->forceFill(['storage_path' => $base, 'width' => min(65535, $w), 'height' => min(65535, $h), 'status' => 'stored', 'error' => null])->save();
            $this->refreshCover($listing);

            return true;
        } catch (\Throwable $e) {
            $giveUp = $media->first_attempt_at !== null && $media->first_attempt_at->lt(CarbonImmutable::now()->subDay());
            $media->forceFill(['status' => $giveUp ? 'failed' : 'pending', 'error' => mb_substr($e->getMessage(), 0, 200)])->save();

            return $giveUp;
        }
    }

    /**
     * Public URL of a stored photo at a size (thumb, card, hero).
     */
    public static function url(?string $path, string $size = 'card'): ?string
    {
        if ($path === null) {
            return null;
        }
        $width = (int) (config('listings.sizes')[$size] ?? 800);
        $cdn = config('listings.cdn_url');

        return is_string($cdn) && $cdn !== '' ? rtrim($cdn, '/')."/{$path}-{$width}.webp" : Storage::disk((string) config('listings.media_disk', 'public'))->url("{$path}-{$width}.webp");
    }

    /**
     * Each photo's alt text is the MLS caption, else the tagged description (C03, Live only), else null.
     *
     * @return list<array{url: string|null, hero: string|null, thumb: string|null, caption: string|null, alt: string|null, room: string|null}>
     */
    public static function forListing(Listing $listing): array
    {
        return array_values(ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'stored')->orderBy('position')->get()
            ->map(fn (ListingMedia $m): array => ['url' => self::url($m->storage_path), 'hero' => self::url($m->storage_path, 'hero'), 'thumb' => self::url($m->storage_path, 'thumb'),
                'caption' => $m->caption, 'alt' => $m->caption ?? ($listing->photos_tagged_at !== null ? $m->alt_text : null), 'room' => $listing->photos_tagged_at !== null ? $m->room : null])->all());
    }

    private function refreshCover(Listing $listing): void
    {
        $cover = ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'stored')->orderBy('position')->value('storage_path');
        if ($listing->getAttribute('cover_path') !== $cover) {
            $listing->forceFill(['cover_path' => $cover])->saveQuietly();
        }
    }

    private function download(string $url): string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || self::isPrivate($host)) {
            throw new RuntimeException('Only public https photo addresses are fetched.');
        }
        $response = $this->http->timeout(30)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->get($url);
        if ($response->failed()) {
            throw new RuntimeException('The MLS photo server answered '.$response->status().'.');
        }
        $type = (string) $response->header('Content-Type');
        if ($type !== '' && ! str_starts_with(strtolower($type), 'image/')) {
            throw new RuntimeException('Not an image ('.$type.').');
        }
        $body = $response->body();
        if (strlen($body) > (int) config('listings.max_photo_bytes')) {
            throw new RuntimeException('Photo too large.');
        }

        return $body;
    }

    private static function isPrivate(string $host): bool
    {
        if (in_array(strtolower($host), ['localhost', 'metadata.google.internal'], true) || str_ends_with(strtolower($host), '.internal') || str_ends_with(strtolower($host), '.local')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }

    private function deleteFiles(ListingMedia $m): void
    {
        if ($m->storage_path === null) {
            return;
        }
        $disk = Storage::disk((string) config('listings.media_disk', 'public'));
        foreach ((array) config('listings.sizes') as $width) {
            $disk->delete("{$m->storage_path}-{$width}.webp");
        }
    }
}
