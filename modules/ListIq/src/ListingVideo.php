<?php

declare(strict_types=1);

namespace Modules\ListIq;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Studio\BrandKits;
use Modules\Ads\Studio\MusicLibrary;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\Video\Bumpers;
use Modules\Ads\Studio\Video\VideoMaker;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\ListIq\Jobs\RenderListingVideo;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Models\Prep;
use Throwable;

/**
 * A listing video from the List-IQ photos (MD1, C21, specs/listings/02): a vertical video for Reels, Shorts and
 * TikTok (1080 × 1920), made on RightAlly's own renderer before the home is on the MLS. A cover with the address and
 * price, up to 12 photos in the set's order with their room captions (staged photos keep their "Virtually staged"
 * label), and an ending with the agent and brokerage; quiet music from the Studio library. Paid with credits
 * (`listiq.video`), refunded if it fails. "Coming soon" until the listing is in the MLS, then "Just listed".
 */
final class ListingVideo
{
    public const MAX_PHOTOS = 12;

    public const MIN_PHOTOS = 5;

    public function __construct(private readonly TenantContext $tenants) {}

    public function start(Prep $prep, User $by): void
    {
        if ($prep->video_status === 'making') {
            throw ValidationException::withMessages(['video' => 'Your video is already being made.']);
        }
        if (self::photos($prep) === [] || count(self::photos($prep)) < self::MIN_PHOTOS) {
            throw ValidationException::withMessages(['video' => 'Add at least '.self::MIN_PHOTOS.' photos first.']);
        }
        $ref = 'listiq.video:'.Str::ulid();
        try {
            app(CreditLedger::class)->charge('listiq.video', $by->id, $ref);
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['video' => $e->getMessage()]);
        }
        $prep->forceFill(['video_status' => 'making', 'video_error' => null, 'video_charge_ref' => $ref])->save();
        RenderListingVideo::dispatch($prep->id, $by->id);
        app(AuditLogger::class)->record('listiq.video_started', metadata: ['listing' => $prep->ulid]);
    }

    public function render(Prep $prep, User $by): void
    {
        $dir = storage_path('app/listiq-video/'.strtolower($prep->ulid));
        File::ensureDirectoryExists($dir);
        try {
            $photos = self::photos($prep);
            $disk = Storage::disk('public');
            $facts = (array) ($prep->facts ?? []);
            $price = isset($facts['price']) ? '$'.number_format((int) $facts['price']) : '';
            $line = implode(' · ', array_filter([isset($facts['beds']) ? $facts['beds'].' beds' : null, isset($facts['baths']) ? $facts['baths'].' baths' : null,
                isset($facts['sqft']) ? number_format((int) $facts['sqft']).' sq ft' : null]));
            $brokerage = (string) $this->tenants->current()->name;
            $frames = [];
            $seconds = [];
            $words = []; // what each frame says, for the captions file
            $shot = function (string $html, float $s, string $said) use (&$frames, &$seconds, &$words, $dir): void {
                $words[] = $said;
                $png = app(StudioRenderer::class)->render($html, 1080, 1920);
                file_put_contents($frames[] = $dir.'/'.sprintf('%02d', count($frames) + 1).'.png', $png);
                $seconds[] = $s;
            };
            $first = $photos[0];
            $headline = $prep->in_mls ? 'Just listed' : 'Coming soon';
            $where = trim($prep->street.', '.$prep->city);
            $sub = trim($price.($line !== '' ? '  ·  '.$line : ''));
            $shot(self::frame((string) $disk->get((string) $first->path), $headline, $where, $sub), 4.0, trim($headline.': '.$where.'. '.$sub));
            foreach ($photos as $p) {
                $shot(self::frame((string) $disk->get((string) $p->path), null, (string) $p->caption, null), 3.0, (string) $p->caption);
            }
            $phone = (string) ($by->getAttribute('phone') ?? '');
            $shot(self::ending($by->name, $phone, $brokerage), 4.0, trim('Ask me about this home: '.$by->name.' '.$phone.', '.$brokerage));
            // MK3: the agent's brand intro and outro, when they asked for them.
            [$frames, $seconds, $bumped] = Bumpers::wrap(app(BrandKits::class)->forUser($by), $frames, $seconds, $dir, 1080, 1920);
            if ($bumped) {
                $kit = app(BrandKits::class)->forUser($by);
                $words = [trim(($kit['details']['display_name'] ?? '').', '.$kit['brokerage']), ...$words, 'Ask me anything: '.trim(($kit['details']['display_name'] ?? '').' '.($kit['details']['phone'] ?? ''))];
            }
            $track = app(MusicLibrary::class)->pick(null, 'cinematic', 0);
            $mp4 = app(VideoMaker::class)->make($frames, $seconds, $track['file'] ?? null, $dir.'/video.mp4');
            $base = 'listiq/'.$this->tenants->id().'/'.$prep->ulid.'/video-'.Str::random(10);
            $disk->put($base.'.mp4', (string) file_get_contents($mp4), ['visibility' => 'public', 'ContentType' => 'video/mp4']);
            $disk->put($base.'.vtt', self::captions($words, $seconds), ['visibility' => 'public', 'ContentType' => 'text/vtt']); // for people who can't see the screen
            $img = @imagecreatefromstring((string) file_get_contents($frames[0]));
            ob_start();
            if ($img !== false) {
                imagejpeg($img, null, 88);
            }
            $disk->put($base.'.jpg', (string) ob_get_clean(), ['visibility' => 'public']);
            foreach (array_filter([$prep->video_path, $prep->video_poster_path, $prep->video_path !== null ? self::captionsPath($prep->video_path) : null]) as $old) {
                $disk->delete($old);
            }
            $prep->forceFill(['video_status' => 'ready', 'video_path' => $base.'.mp4', 'video_poster_path' => $base.'.jpg', 'video_error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $prep->forceFill(['video_status' => 'failed', 'video_error' => 'The video couldn\'t be made just now. Your credits were returned.'])->save();
            if ($prep->video_charge_ref !== null) {
                app(CreditLedger::class)->refund($prep->video_charge_ref, 'Listing video did not render');
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** The captions file next to a video. */
    public static function captionsPath(string $videoPath): string
    {
        return preg_replace('/\.mp4$/', '.vtt', $videoPath) ?? $videoPath;
    }

    /**
     * WebVTT captions: what each frame shows, for its time on screen (the frames cross-fade for 0.6 seconds).
     *
     * @param  list<string>  $words
     * @param  list<float>  $seconds
     */
    private static function captions(array $words, array $seconds): string
    {
        $t = 0.0;
        $out = "WEBVTT\n";
        $stamp = fn (float $s): string => sprintf('%02d:%02d:%06.3f', intdiv((int) $s, 3600), intdiv((int) $s % 3600, 60), fmod($s, 60));
        foreach ($words as $i => $w) {
            $end = $t + $seconds[$i] - ($i < count($words) - 1 ? 0.6 : 0);
            $out .= "\n".($i + 1)."\n".$stamp($t).' --> '.$stamp($end)."\n".str_replace(["\r", "\n", '-->'], [' ', ' ', '-'], $w)."\n";
            $t = $end;
        }

        return $out;
    }

    /** @return list<Photo> the photos in the set, in order (at most 12) */
    public static function photos(Prep $prep): array
    {
        return array_values(Photo::query()->where('listing_id', $prep->id)->where('use', true)->where('status', 'ready')->whereNotNull('path')
            ->orderBy('position')->limit(self::MAX_PHOTOS)->get()->all());
    }

    /** One frame: the photo filling the screen, with an optional headline and a caption band. */
    private static function frame(string $jpeg, ?string $headline, string $caption, ?string $sub): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $img = 'data:image/jpeg;base64,'.base64_encode($jpeg);

        return '<!doctype html><html><head><meta charset="utf-8"><style>*{margin:0;box-sizing:border-box}body{width:1080px;height:1920px;font-family:Inter,Helvetica,Arial,sans-serif;'
            .'background:#111 url('.$img.') center/cover no-repeat;position:relative;color:#fff}'
            .'.band{position:absolute;left:0;right:0;bottom:0;padding:80px 72px 120px;background:linear-gradient(transparent,rgba(0,0,0,.78))}'
            .'h1{font-size:96px;font-weight:800;letter-spacing:-1px;margin-bottom:24px;text-transform:uppercase}p{font-size:54px;font-weight:600;line-height:1.25}'
            .'.sub{font-size:46px;font-weight:500;margin-top:16px;opacity:.95}</style></head><body><div class="band">'
            .($headline !== null ? '<h1>'.$e($headline).'</h1>' : '').'<p>'.$e($caption).'</p>'.($sub !== null && $sub !== '' ? '<p class="sub">'.$e($sub).'</p>' : '')
            .'</div></body></html>';
    }

    private static function ending(string $agent, string $phone, string $brokerage): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

        return '<!doctype html><html><head><meta charset="utf-8"><style>*{margin:0}body{width:1080px;height:1920px;display:flex;flex-direction:column;justify-content:center;'
            .'align-items:center;text-align:center;background:#14213d;color:#fff;font-family:Inter,Helvetica,Arial,sans-serif;gap:28px;padding:80px}'
            .'h1{font-size:84px;font-weight:800}p{font-size:52px}</style></head><body><p>Ask me about this home</p><h1>'.$e($agent).'</h1>'
            .($phone !== '' ? '<p>'.$e($phone).'</p>' : '').'<p>'.$e($brokerage).'</p></body></html>';
    }
}
