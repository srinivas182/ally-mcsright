<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Modules\Ads\Jobs\RenderStylePreviews;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * "See your listing in every style" (specs/grow/13, SA1d): each style rendered with the person's own newest
 * listing (or their portrait), headshot and logo, in the feed size. Previews are free; they are made again only
 * when the brand kit or the sample changes, at most once a day.
 */
final class StylePreviews
{
    public function __construct(private readonly Designer $designer, private readonly BrandKits $kits, private readonly TenantContext $tenants) {}

    /**
     * Each style's preview URL, or null while it is being made (making them starts on the first ask).
     *
     * @return array<string, string|null>
     */
    public function urls(User $user): array
    {
        [$dir, $hash] = $this->where($user);
        $disk = Storage::disk('public');
        $out = [];
        $missing = false;
        foreach (array_keys(Styles::ALL) as $key) {
            $path = $dir.$key.'-'.$hash.'.jpg';
            $exists = $disk->exists($path);
            $missing = $missing || ! $exists;
            $out[$key] = $exists ? self::url($path) : null;
        }
        if ($missing && Cache::add('studio-previews:'.$this->tenants->id().':'.$user->id.':'.$hash, 1, now()->addDay())) {
            RenderStylePreviews::dispatch($user->id);
        }

        return $out;
    }

    /** Renders the missing previews (the queue job). */
    public function make(User $user): void
    {
        [$dir, $hash] = $this->where($user);
        $kit = $this->kits->forUser($user);
        $sample = $this->designer->sample($user);
        foreach (Styles::ALL as $key => $t) {
            $path = $dir.$key.'-'.$hash.'.jpg';
            if (Storage::disk('public')->exists($path)) {
                continue;
            }
            $layout = $sample['kind'] === 'listing' ? (string) $t['layouts'][0] : 'statement';
            try {
                $png = app(StudioRenderer::class)->render($this->designer->page(['finish' => null] + $kit, $key, $layout, $sample['kind'], $sample['content'], $sample['photos'], 1080, 1350), 1080, 1350);
                $img = imagecreatefromstring($png);
                if ($img === false) {
                    continue;
                }
                $small = imagescale($img, 540, 675) ?: $img;
                ob_start();
                imagejpeg($small, null, 86);
                Storage::disk('public')->put($path, (string) ob_get_clean(), ['visibility' => 'public']);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @return array{0: string, 1: string} the folder and a fingerprint of what the previews show */
    private function where(User $user): array
    {
        $kit = $this->kits->forUser($user);
        $sample = $this->designer->sample($user);
        $hash = substr(sha1((string) json_encode([$kit['colors'], $kit['logo'], $kit['logo_dark'], $kit['cutout'], $kit['headshot'], $kit['details'], $sample, 'v1'])), 0, 12);

        return ['studio/'.strtolower($this->tenants->current()->ulid).'/previews/'.$user->id.'/', $hash];
    }

    private static function url(string $path): string
    {
        $cdn = config('listings.cdn_url');

        return is_string($cdn) && $cdn !== '' ? rtrim($cdn, '/').'/'.$path : Storage::disk('public')->url($path);
    }
}
