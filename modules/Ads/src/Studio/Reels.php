<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Jobs\RenderReel;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\Video\VideoMaker;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Reels and Shorts (specs/grow/13, SA3): a listing design becomes a 9:16 video in the same style — the cover, each
 * photo and the details card, with slow camera moves, crossfades and a licensed track. Making one is quick; it
 * counts as one "Reel or Short" when first downloaded or posted (credits past the allowance).
 */
final class Reels
{
    /** Seconds per frame: the cover, each photo, the details card. */
    public const COVER_SECONDS = 4.0;

    public const PHOTO_SECONDS = 3.0;

    public const DETAILS_SECONDS = 4.5;

    public function __construct(private readonly StudioPlans $plans, private readonly Designer $designer, private readonly BrandKits $kits,
        private readonly MusicLibrary $music, private readonly TenantContext $tenants) {}

    public function make(User $by, Design $design, ?string $track = null, string $mood = 'luxury'): StudioVideo
    {
        abort_unless($design->user_id === $by->id, 404);
        if ($this->plans->current($by) === null) {
            throw ValidationException::withMessages(['reel' => 'Ally Studio is an add-on. Choose a Studio package first.']);
        }
        if ($design->status !== 'ready' || (Designer::MOMENTS[$design->moment][1] ?? '') !== 'listing') {
            throw ValidationException::withMessages(['reel' => 'Reels are made from a finished listing design.']);
        }
        $today = StudioVideo::query()->where('user_id', $by->id)->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->count();
        if ($today >= (int) config('studio.daily_reels', 10)) {
            throw ValidationException::withMessages(['reel' => 'That\'s a lot of Reels for one day. Try again tomorrow.']);
        }
        $picked = $this->music->pick($track, isset(MusicLibrary::MOODS[$mood]) ? $mood : 'luxury', StudioVideo::query()->where('user_id', $by->id)->count());
        $video = StudioVideo::query()->create(['user_id' => $by->id, 'design_id' => $design->id, 'listing_ulid' => $design->listing_ulid, 'music_ulid' => $picked['ulid'] ?? null,
            'status' => 'rendering']);
        RenderReel::dispatch($video->id);
        app(AuditLogger::class)->record('studio.reel_made', metadata: ['design' => $design->ulid, 'music' => $picked['ulid'] ?? null]);

        return $video;
    }

    /** Draws the frames in the design's style at story size and turns them into the video. */
    public function render(StudioVideo $video): void
    {
        $user = User::query()->find($video->user_id);
        $design = Design::query()->find($video->design_id);
        if ($user === null || $design === null) {
            $video->forceFill(['status' => 'failed', 'error' => 'The design is gone.'])->save();

            return;
        }
        $kit = $this->kits->forUser($user);
        $photos = array_values(array_filter((array) ($design->content['photos'] ?? [$design->content['photo'] ?? null]), 'is_string'));
        $content = $design->content + ['moment' => $design->moment];
        [$w, $h] = Designer::SIZES['story'];
        $dir = storage_path('app/studio-reels/'.strtolower($video->ulid));
        File::ensureDirectoryExists($dir);
        try {
            $plan = [['cover', $photos, self::COVER_SECONDS]];
            foreach (Designer::slides($photos) as [$slide, $shots]) {
                $plan[] = [$slide, $shots, $slide === 'details' ? self::DETAILS_SECONDS : self::PHOTO_SECONDS];
            }
            $frames = [];
            $seconds = [];
            foreach ($plan as $i => [$slide, $shots, $s]) {
                $png = app(StudioRenderer::class)->render($this->designer->page($kit, $design->collection, $design->variant, 'listing', $content, $shots, $w, $h,
                    $slide, $i + 1, count($plan)), $w, $h);
                file_put_contents($frames[] = $dir.'/'.sprintf('%02d', $i + 1).'.png', $png);
                $seconds[] = $s;
            }
            $track = $video->music_ulid !== null ? $this->music->pick($video->music_ulid, 'luxury', 0) : null;
            $mp4 = app(VideoMaker::class)->make($frames, $seconds, $track['file'] ?? null, $dir.'/reel.mp4');
            $base = 'studio/'.strtolower($this->tenants->current()->ulid).'/videos/'.strtolower($video->ulid);
            $disk = Storage::disk('public');
            $disk->put($base.'.mp4', (string) file_get_contents($mp4), ['visibility' => 'public', 'ContentType' => 'video/mp4']);
            $img = @imagecreatefromstring((string) file_get_contents($frames[0]));
            ob_start();
            if ($img !== false) {
                imagejpeg($img, null, 90);
            }
            $disk->put($base.'.jpg', (string) ob_get_clean(), ['visibility' => 'public']);
            $total = array_sum($seconds) - 0.6 * (count($seconds) - 1);
            $video->forceFill(['status' => 'ready', 'path' => $base.'.mp4', 'poster_path' => $base.'.jpg', 'seconds' => (int) round($total), 'error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $video->forceFill(['status' => 'failed', 'error' => 'The Reel couldn\'t be made just now. Please try again.'])->save();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** The first download or post counts once against this month's Reels (or credits). */
    public function use(StudioVideo $video, User $by): void
    {
        abort_unless($video->user_id === $by->id && $video->status === 'ready', 404);
        if ($video->used_at === null) {
            $this->plans->use($by, 'reel', $video->ulid);
            $video->forceFill(['used_at' => CarbonImmutable::now()])->save();
        }
    }

    public function url(StudioVideo $video): ?string
    {
        return $video->path !== null ? self::link($video->path) : null;
    }

    public function poster(StudioVideo $video): ?string
    {
        return $video->poster_path !== null ? self::link($video->poster_path) : null;
    }

    private static function link(string $path): string
    {
        $cdn = config('listings.cdn_url');

        return is_string($cdn) && $cdn !== '' ? rtrim($cdn, '/').'/'.$path : Storage::disk('public')->url($path);
    }
}
