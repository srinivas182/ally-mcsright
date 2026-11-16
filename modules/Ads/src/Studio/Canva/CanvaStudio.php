<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Canva;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Jobs\ImportCanvaDesign;
use Modules\Ads\Jobs\PrepareCanvaDesign;
use Modules\Ads\ListingAds;
use Modules\Ads\Studio\BrandKits;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\StudioPlans;
use Modules\Connect\Models\Connection;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Throwable;

/**
 * Canva in Ally Studio (specs/grow/13, C7d-3).
 *
 * 1. "Open in Canva" from a listing or a Studio design: the brand logo, the agent's headshot and the listing photos
 *    (or the Studio design itself) go to the person's Canva uploads, and a 4:5 design starts with the cover in it.
 * 2. They design in Canva and press Return: Canva sends them to RightAlly's one return address with a signed token,
 *    which says which brokerage website to go back to.
 * 3. The finished pages come back as a Studio design (the first page as the post, the rest as carousel slides; square
 *    and story sizes made from it), ready to post, advertise or download like any other.
 */
final class CanvaStudio
{
    public const WIDTH = 1080;

    public const HEIGHT = 1350;

    private const PHOTOS = 4;

    public function __construct(private readonly CanvaApi $api, private readonly ConnectorKit $kit, private readonly TenantContext $tenants) {}

    public function connection(User $user): ?Connection
    {
        return Connection::query()->where('app_key', 'canva')->where('owner_user_id', $user->id)->whereIn('status', ['connected', 'error'])->first();
    }

    public function start(User $user, ?string $listing, ?string $design, string $host): CanvaSession
    {
        if (app(StudioPlans::class)->current($user) === null) {
            throw ValidationException::withMessages(['canva' => 'Ally Studio is an add-on. Choose a Studio package first.']);
        }
        $c = $this->connection($user) ?? throw ValidationException::withMessages(['canva' => 'Connect Canva first (Apps → Canva).']);
        $source = $design !== null && $design !== '' ? Design::query()->where('ulid', $design)->where('user_id', $user->id)->where('status', 'ready')->first()
            ?? throw ValidationException::withMessages(['canva' => 'Choose one of your finished designs.']) : null;
        $listing = $source->listing_ulid ?? ($listing !== null && $listing !== '' ? $listing : null);
        if ($listing !== null && $source === null && app(ListingAds::class)->listing($user, $listing) === null) {
            throw ValidationException::withMessages(['canva' => 'Choose one of your listings.']);
        }
        $s = CanvaSession::query()->create(['user_id' => $user->id, 'connection_id' => $c->id, 'listing_ulid' => $listing, 'source_design_id' => $source?->id, 'status' => 'preparing']);
        DB::connection(Connections::control())->table('canva_returns')->insert(['state' => strtoupper($s->ulid), 'tenant_id' => $this->tenants->id(), 'host' => mb_substr($host, 0, 190),
            'created_at' => now()]);
        PrepareCanvaDesign::dispatch($s->id);
        app(AuditLogger::class)->record('studio.canva_opened', metadata: ['session' => $s->ulid, 'listing' => $listing]);

        return $s;
    }

    /** Uploads the brand and photos and makes the design (queued). */
    public function prepare(CanvaSession $s): void
    {
        try {
            $user = User::query()->find($s->user_id) ?? throw new \RuntimeException('The person is gone.');
            $token = $this->token($s);
            $images = $this->images($s, $user);
            $first = null;
            foreach ($images as $i => [$name, $bytes]) {
                $id = $this->api->upload($token, $bytes, $name);
                $first ??= $i === 0 ? $id : null;
            }
            $title = $s->listing_ulid !== null ? 'RightAlly · '.(Listing::query()->where('ulid', $s->listing_ulid)->first()?->address() ?? 'Listing') : 'RightAlly post';
            $made = $this->api->create($token, self::WIDTH, self::HEIGHT, $first, $title);
            // correlation_state brings the person back to this session (Canva allows 50 URL-safe characters).
            $url = $made['edit_url'].(str_contains($made['edit_url'], '?') ? '&' : '?').'correlation_state='.strtoupper($s->ulid);
            $s->forceFill(['design_ref' => $made['id'], 'edit_url' => $url, 'status' => 'editing', 'error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $s->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();
        }
    }

    /** The person came back from Canva: bring the design in (queued). */
    public function back(CanvaSession $s, User $user): void
    {
        abort_unless($s->user_id === $user->id, 404);
        if (in_array($s->status, ['editing', 'ready', 'failed'], true) && $s->design_ref !== null) {
            $s->forceFill(['status' => 'importing', 'error' => null])->save();
            ImportCanvaDesign::dispatch($s->id);
        }
    }

    /** Exports the pages and makes them a Studio design. */
    public function import(CanvaSession $s): void
    {
        try {
            $user = User::query()->find($s->user_id) ?? throw new \RuntimeException('The person is gone.');
            $pages = $this->api->export($this->token($s), (string) $s->design_ref);
            $base = 'studio/'.strtolower($this->tenants->current()->ulid).'/canva/'.strtolower($s->ulid).'-'.time();
            $disk = Storage::disk('public');
            $files = [];
            foreach (array_slice($pages, 0, 10) as $i => $url) {
                $png = Http::timeout(60)->get($url);
                if (! $png->successful() || @imagecreatefromstring($png->body()) === false) {
                    throw new \RuntimeException('Canva\'s export could not be read.');
                }
                $disk->put($files[] = $base.'-'.($i + 1).'.png', $png->body(), ['visibility' => 'public']);
            }
            $cover = (string) $disk->get($files[0]);
            $disk->put($square = $base.'-square.jpg', self::fit($cover, 1080, 1080, false), ['visibility' => 'public']);
            $disk->put($story = $base.'-story.jpg', self::fit($cover, 1080, 1920, true), ['visibility' => 'public']);
            $source = $s->source_design_id !== null ? Design::query()->find($s->source_design_id) : null;
            $kit = app(BrandKits::class)->forUser($user);
            $d = Design::query()->create(['user_id' => $user->id, 'batch' => strtolower((string) Str::ulid()), 'moment' => $source->moment ?? ($s->listing_ulid !== null ? 'just_listed' : 'tip'),
                'listing_ulid' => $s->listing_ulid, 'collection' => $source->collection ?? $kit['collection'], 'variant' => 'canva',
                'content' => ['headline' => 'Made in Canva', 'source' => 'canva'], 'status' => 'ready']);
            $d->forceFill(['files' => ['portrait' => $files[0], 'square' => $square, 'story' => $story] + (count($files) > 1 ? ['slides' => array_slice($files, 1)] : [])])->save();
            $s->forceFill(['status' => 'ready', 'result_design_id' => $d->id, 'error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $s->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();
        }
    }

    /** Is this a design brought back from Canva (its art is Canva's, so Studio doesn't redraw it as a Reel)? */
    public static function fromCanva(Design $d): bool
    {
        return $d->variant === 'canva';
    }

    private function token(CanvaSession $s): string
    {
        $c = Connection::query()->find($s->connection_id) ?? throw new \RuntimeException('Connect Canva again.');

        return $this->kit->accessToken($c->data());
    }

    /**
     * What goes to the person's Canva uploads: the starting image first (the Studio design, or the listing's cover),
     * then more listing photos, the brokerage logo and the agent's headshot.
     *
     * @return list<array{0: string, 1: string}> name, bytes
     */
    private function images(CanvaSession $s, User $user): array
    {
        $out = [];
        $public = Storage::disk('public');
        $source = $s->source_design_id !== null ? Design::query()->find($s->source_design_id) : null;
        if ($source !== null && is_string($source->files['portrait'] ?? null) && $public->exists($source->files['portrait'])) {
            $out[] = ['Studio design', (string) $public->get($source->files['portrait'])];
        }
        if ($s->listing_ulid !== null) {
            $home = Listing::query()->where('ulid', $s->listing_ulid)->first();
            $media = Storage::disk((string) config('listings.media_disk', 'public'));
            foreach ($home !== null ? ListingMedia::query()->where('listing_id', $home->id)->where('status', 'stored')->orderBy('position')->limit(self::PHOTOS)->get() : [] as $i => $m) {
                $path = $m->storage_path.'-1600.webp';
                if ($media->exists($path) && ($jpg = self::jpeg((string) $media->get($path))) !== null) {
                    $out[] = ['Listing photo '.($i + 1), $jpg];
                }
            }
        }
        $kit = app(BrandKits::class);
        foreach (['Brokerage logo' => $kit->paths($user)['logo'], 'Headshot' => $kit->paths($user)['headshot']] as $name => $path) {
            if ($path !== null && $public->exists($path)) {
                $out[] = [$name, (string) $public->get($path)];
            }
        }

        return $out;
    }

    private static function jpeg(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }
        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * The cover at another size: cropped to fill (square), or whole on a soft, blurred copy of itself (story).
     *
     * @param  int<1, max>  $w
     * @param  int<1, max>  $h
     */
    private static function fit(string $bytes, int $w, int $h, bool $contain): string
    {
        $src = @imagecreatefromstring($bytes) ?: throw new \RuntimeException('Canva\'s export could not be read.');
        [$sw, $sh] = [imagesx($src), imagesy($src)];
        $out = imagecreatetruecolor($w, $h);
        $cover = max($w / $sw, $h / $sh);
        [$cw, $ch] = [(int) ceil($sw * $cover), (int) ceil($sh * $cover)];
        imagecopyresampled($out, $src, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), 0, 0, $cw, $ch, $sw, $sh);
        if ($contain) {
            for ($i = 0; $i < 12; $i++) {
                imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR);
            }
            imagefilter($out, IMG_FILTER_BRIGHTNESS, -40);
            $fit = min($w / $sw, $h / $sh);
            [$fw, $fh] = [(int) round($sw * $fit), (int) round($sh * $fit)];
            imagecopyresampled($out, $src, (int) (($w - $fw) / 2), (int) (($h - $fh) / 2), 0, 0, $fw, $fh, $sw, $sh);
        }
        ob_start();
        imagejpeg($out, null, 92);

        return (string) ob_get_clean();
    }
}
