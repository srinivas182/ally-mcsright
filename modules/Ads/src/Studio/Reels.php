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
use Modules\Ads\Studio\Video\Bumpers;
use Modules\Ads\Studio\Video\VideoMaker;
use Modules\Ads\Studio\Voice\Narration;
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

    /** YouTube tours: the intro, up to six rooms of ten seconds (long enough to be YouTube chapters), the outro. */
    public const TOUR_INTRO = 6.0;

    public const TOUR_ROOM = 10.0;

    public const TOUR_OUTRO = 8.0;

    public const TOUR_ROOMS = 6;

    public function __construct(private readonly StudioPlans $plans, private readonly Designer $designer, private readonly BrandKits $kits,
        private readonly MusicLibrary $music, private readonly TenantContext $tenants) {}

    public function make(User $by, Design $design, ?string $track = null, string $mood = 'luxury'): StudioVideo
    {
        abort_unless($design->user_id === $by->id, 404);
        if ($this->plans->current($by) === null) {
            throw ValidationException::withMessages(['reel' => 'Ally Studio is an add-on. Choose a Studio package first.']);
        }
        if ($design->status !== 'ready' || (Designer::MOMENTS[$design->moment][1] ?? '') !== 'listing' || $design->variant === 'canva') {
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

    /** A YouTube listing tour (landscape, with chapters) from one of the person's listings. */
    public function tour(User $by, string $listing): StudioVideo
    {
        if ($this->plans->current($by) === null) {
            throw ValidationException::withMessages(['tour' => 'Ally Studio is an add-on. Choose a Studio package first.']);
        }
        $this->designer->tourOf($by, $listing); // the person's own listing, with sharp photos
        $today = StudioVideo::query()->where('user_id', $by->id)->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->count();
        if ($today >= (int) config('studio.daily_reels', 10)) {
            throw ValidationException::withMessages(['tour' => 'That\'s a lot of videos for one day. Try again tomorrow.']);
        }
        $picked = $this->music->pick(null, 'cinematic', StudioVideo::query()->where('user_id', $by->id)->count());
        $video = StudioVideo::query()->create(['user_id' => $by->id, 'design_id' => null, 'listing_ulid' => $listing, 'music_ulid' => $picked['ulid'] ?? null, 'status' => 'rendering']);
        $video->forceFill(['kind' => 'tour'])->save();
        RenderReel::dispatch($video->id);
        app(AuditLogger::class)->record('studio.tour_made', metadata: ['listing' => $listing]);

        return $video;
    }

    /** Draws the frames in the design's style at story size and turns them into the video (or the tour, landscape). */
    public function render(StudioVideo $video): void
    {
        if ($video->kind === 'tour') {
            $this->renderTour($video);

            return;
        }
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
            [$frames, $seconds] = Bumpers::wrap($kit, $frames, $seconds, $dir, $w, $h); // MK3: brand intro and outro
            $track = $video->music_ulid !== null ? $this->music->pick($video->music_ulid, 'luxury', 0) : null;
            $voice = app(Narration::class)->audioFor($video, $dir);
            $mp4 = app(VideoMaker::class)->make($frames, $seconds, $track['file'] ?? null, $dir.'/reel.mp4', voice: $voice);
            $base = 'studio/'.strtolower($this->tenants->current()->ulid).'/videos/'.strtolower($video->ulid).self::version($video, $voice);
            $disk = Storage::disk('public');
            $disk->put($base.'.mp4', (string) file_get_contents($mp4), ['visibility' => 'public', 'ContentType' => 'video/mp4']);
            $img = @imagecreatefromstring((string) file_get_contents($frames[0]));
            ob_start();
            if ($img !== false) {
                imagejpeg($img, null, 90);
            }
            $disk->put($base.'.jpg', (string) ob_get_clean(), ['visibility' => 'public']);
            $total = array_sum($seconds) - 0.6 * (count($seconds) - 1);
            $this->replaced($video, $base);
            $video->forceFill(['status' => 'ready', 'path' => $base.'.mp4', 'poster_path' => $base.'.jpg', 'seconds' => (int) round($total), 'error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $this->failed($video, 'The Reel couldn\'t be made just now. Please try again.');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function renderTour(StudioVideo $video): void
    {
        $user = User::query()->find($video->user_id);
        $dir = storage_path('app/studio-reels/'.strtolower($video->ulid));
        File::ensureDirectoryExists($dir);
        try {
            if ($user === null || $video->listing_ulid === null) {
                throw ValidationException::withMessages(['tour' => 'The listing is gone.']);
            }
            $kit = $this->kits->forUser($user);
            $tour = $this->designer->tourOf($user, $video->listing_ulid);
            $photos = $tour['photos'];
            $rooms = array_slice(array_keys($photos), 1, self::TOUR_ROOMS);
            $rooms = $rooms === [] ? [0] : $rooms;
            $plan = [['intro', $photos[0], 'Welcome', self::TOUR_INTRO]];
            foreach ($rooms as $i => $k) {
                $plan[] = ['chapter', $photos[$k], $tour['captions'][$k] ?? 'A closer look', self::TOUR_ROOM];
            }
            $plan[] = ['outro', $photos[count($photos) - 1], 'Book a tour', self::TOUR_OUTRO];
            $frames = [];
            $seconds = [];
            $chapters = [];
            $at = 0.0;
            foreach ($plan as $i => [$slide, $photo, $title, $s]) {
                $png = app(StudioRenderer::class)->render($this->designer->tourPage($kit, $kit['collection'], $slide, $tour['content'], $photo, $title, max(1, $i), count($rooms)), 1920, 1080);
                file_put_contents($frames[] = $dir.'/'.sprintf('%02d', $i + 1).'.png', $png);
                $seconds[] = $s;
                $chapters[] = ['at' => (int) floor($at), 'title' => $title];
                $at += $s - 0.6;
            }
            [$frames, $seconds, $bumped] = Bumpers::wrap($kit, $frames, $seconds, $dir, 1920, 1080); // MK3: brand intro and outro
            if ($bumped) {
                $chapters = array_map(fn (array $c): array => ['at' => $c['at'] === 0 ? 0 : (int) floor($c['at'] + Bumpers::INTRO - 0.6), 'title' => $c['title']], $chapters);
                $at += Bumpers::INTRO + Bumpers::OUTRO - 1.2;
            }
            $track = $video->music_ulid !== null ? $this->music->pick($video->music_ulid, 'cinematic', 0) : null;
            $voice = app(Narration::class)->audioFor($video, $dir);
            $mp4 = app(VideoMaker::class)->make($frames, $seconds, $track['file'] ?? null, $dir.'/tour.mp4', 1920, 1080, $voice);
            $base = 'studio/'.strtolower($this->tenants->current()->ulid).'/tours/'.strtolower($video->ulid).self::version($video, $voice);
            $disk = Storage::disk('public');
            $disk->put($base.'.mp4', (string) file_get_contents($mp4), ['visibility' => 'public', 'ContentType' => 'video/mp4']);
            $img = @imagecreatefromstring((string) file_get_contents($frames[0]));
            ob_start();
            if ($img !== false) {
                imagejpeg($img, null, 90);
            }
            $disk->put($base.'.jpg', (string) ob_get_clean(), ['visibility' => 'public']);
            $this->replaced($video, $base);
            $video->forceFill(['status' => 'ready', 'path' => $base.'.mp4', 'poster_path' => $base.'.jpg', 'seconds' => (int) round($at + 0.6), 'chapters' => $chapters,
                'error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $this->failed($video, 'The tour couldn\'t be made just now. Please try again.');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** A first make fails; a re-make (adding narration) keeps the video as it was and gives the narration back. */
    private function failed(StudioVideo $video, string $message): void
    {
        if ($video->path === null) {
            $video->forceFill(['status' => 'failed', 'error' => $message])->save();

            return;
        }
        $user = User::query()->find($video->user_id);
        if ($user !== null && $video->narration !== null) {
            $this->plans->giveBack($user, 'voice');
        }
        $video->forceFill(['status' => 'ready', 'narration' => null, 'voice' => null,
            'narration_error' => 'The voice-over couldn\'t be added just now. Your video is as it was, and the narration is back in your allowance.'])->save();
    }

    /** A narrated video gets its own file name, so nobody is served the old one from a cache. */
    private static function version(StudioVideo $video, ?string $voice): string
    {
        return $voice !== null ? '-n'.substr(sha1((string) $video->narration.$video->voice), 0, 10) : '';
    }

    /** When a video is made again (narrated), the previous files go. */
    private function replaced(StudioVideo $video, string $base): void
    {
        if ($video->path !== null && $video->path !== $base.'.mp4') {
            Storage::disk('public')->delete(array_filter([$video->path, $video->poster_path]));
        }
    }

    /**
     * YouTube chapters for a tour's description ("0:00 Welcome"); YouTube shows them when there are three or more.
     */
    public static function chapterLines(StudioVideo $video): string
    {
        return implode("\n", array_map(fn (array $c): string => sprintf('%d:%02d %s', intdiv((int) $c['at'], 60), (int) $c['at'] % 60, (string) $c['title']),
            (array) ($video->chapters ?? [])));
    }

    /** The first download or post counts once against this month's Reels or tours (or credits). */
    public function use(StudioVideo $video, User $by): void
    {
        abort_unless($video->user_id === $by->id && $video->status === 'ready', 404);
        if ($video->used_at === null) {
            $this->plans->use($by, $video->kind === 'tour' ? 'tour' : 'reel', $video->ulid);
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
