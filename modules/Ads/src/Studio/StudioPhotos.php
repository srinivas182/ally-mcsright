<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Jobs\EnhancePhoto;
use Modules\Ads\ListingAds;
use Modules\Ads\Studio\Enhance\PhotoEnhancer;
use Modules\Ads\Studio\Enhance\PhotoUpscaler;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Throwable;

/**
 * AI photo enhancement for Ally Studio (specs/grow/13, SA5a). An agent picks listing photos (or Autopilot picks a
 * new listing's best ones); each enhanced photo, and each virtual twilight, counts as one "photo enhancement" from
 * the Studio package (credits past the allowance; Autopilot never spends credits on it). RightAlly's own
 * Autoenhance.ai account does the work, and an upscaler doubles the result when HQ switches one on. The MLS
 * originals are never changed, and every design or post made with an enhanced photo says "Photos virtually enhanced".
 */
final class StudioPhotos
{
    public const MAX_PER_REQUEST = 10;

    /** Autopilot enhances this many of a new listing's photos (from the allowance only). */
    public const AUTO_PHOTOS = 3;

    public const LABEL = 'Photos virtually enhanced';

    public function __construct(private readonly StudioPlans $plans, private readonly PhotoEnhancer $enhancer, private readonly PhotoUpscaler $upscaler,
        private readonly TenantContext $tenants) {}

    public function ready(): bool
    {
        return $this->enhancer->ready();
    }

    /**
     * A listing's photos with their enhanced versions, for the Enhance page.
     *
     * @return list<array{id: int, original: string|null, enhanced: array{status: string, url: string|null}|null, twilight: array{status: string, url: string|null}|null}>
     */
    public function forListing(User $user, string $ulid): array
    {
        $listing = $this->listing($user, $ulid);
        $versions = StudioPhoto::query()->where('listing_ulid', $ulid)->get()->groupBy('media_id');
        $row = fn (?StudioPhoto $p): ?array => $p === null ? null : ['status' => $p->status, 'url' => $p->status === 'ready' && $p->path !== null ? self::link($p->path) : null];

        $out = [];
        foreach (ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'stored')->orderBy('position')->limit(24)->get() as $m) {
            $mine = $versions->get($m->id);
            $out[] = ['id' => (int) $m->id, 'original' => Photos::url($m->storage_path, 'card'),
                'enhanced' => $row($mine?->firstWhere('kind', 'enhanced')), 'twilight' => $row($mine?->firstWhere('kind', 'twilight'))];
        }

        return $out;
    }

    /**
     * Starts enhancing photos of a listing, and a virtual twilight of the first one when asked.
     *
     * @param  list<int>  $mediaIds
     * @return int how many enhancements started
     */
    public function request(User $user, string $ulid, array $mediaIds, bool $twilight = false, bool $allowanceOnly = false): int
    {
        if ($this->plans->current($user) === null) {
            throw ValidationException::withMessages(['photos' => 'Ally Studio is an add-on. Choose a Studio package first.']);
        }
        if (! $this->enhancer->ready()) {
            throw ValidationException::withMessages(['photos' => 'Photo enhancement isn\'t switched on yet. RightAlly is setting it up.']);
        }
        $listing = $this->listing($user, $ulid);
        $media = ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'stored')->whereIn('id', array_slice(array_values(array_unique($mediaIds)), 0, self::MAX_PER_REQUEST))
            ->orderBy('position')->get();
        if ($media->isEmpty()) {
            throw ValidationException::withMessages(['photos' => 'Choose at least one photo.']);
        }
        $wanted = [];
        foreach ($media as $i => $m) {
            $wanted[] = [(int) $m->id, 'enhanced'];
            if ($twilight && $i === 0) {
                $wanted[] = [(int) $m->id, 'twilight'];
            }
        }
        $started = 0;
        foreach ($wanted as [$mediaId, $kind]) {
            $photo = StudioPhoto::query()->where('listing_ulid', $ulid)->where('media_id', $mediaId)->where('kind', $kind)->first();
            if ($photo !== null && $photo->status !== 'failed') {
                continue; // already done or on its way
            }
            if ($allowanceOnly && $this->plans->left($user, 'enhance') < 1) {
                break;
            }
            $photo ??= StudioPhoto::query()->create(['user_id' => $user->id, 'listing_ulid' => $ulid, 'media_id' => $mediaId, 'kind' => $kind, 'status' => 'queued']);
            $ref = strtolower($photo->ulid).':'.strtolower(Str::random(6));
            try {
                $paid = $this->plans->use($user, 'enhance', $ref);
            } catch (ValidationException $e) {
                $photo->status === 'queued' ? $photo->delete() : null;
                if ($started === 0) {
                    throw $e;
                }
                break;
            }
            $photo->forceFill(['user_id' => $user->id, 'status' => 'queued', 'paid' => $paid, 'charge_ref' => $ref, 'error' => null, 'provider_ref' => null,
                'path' => null, 'attempts' => 0])->save();
            EnhancePhoto::dispatch($photo->id);
            $started++;
        }
        app(AuditLogger::class)->record('studio.photos_enhanced', metadata: ['listing' => $ulid, 'photos' => $started, 'twilight' => $twilight]);

        return $started;
    }

    /**
     * Autopilot: a new listing's best photos (and a twilight of the cover when the allowance allows), from the
     * allowance only. Returns how many started.
     */
    public function auto(User $user, string $ulid): int
    {
        $left = $this->plans->left($user, 'enhance');
        if ($left < 1 || ! $this->enhancer->ready()) {
            return 0;
        }
        $listing = Listing::query()->where('ulid', $ulid)->first();
        if ($listing === null) {
            return 0;
        }
        $count = min(self::AUTO_PHOTOS, $left);
        $ids = ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'stored')->orderBy('position')->limit($count)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        if ($ids === []) {
            return 0;
        }
        try {
            return $this->request($user, $ulid, array_values($ids), $left > count($ids), true);
        } catch (ValidationException) {
            return 0;
        }
    }

    /** Whether any enhancement of this listing is still on its way. */
    public function pending(string $ulid): bool
    {
        return StudioPhoto::query()->where('listing_ulid', $ulid)->whereIn('status', ['queued', 'processing'])->exists();
    }

    /**
     * The finished versions of a listing's photos, for designs: media id => kind => URL.
     *
     * @return array<int, array<string, string>>
     */
    public function versions(string $ulid): array
    {
        $out = [];
        foreach (StudioPhoto::query()->where('listing_ulid', $ulid)->where('status', 'ready')->whereNotNull('path')->get() as $p) {
            $out[$p->media_id][$p->kind] = self::link((string) $p->path);
        }

        return $out;
    }

    /**
     * One step of an enhancement (the job calls this until it returns true): start it, check on it, or keep the result.
     */
    public function step(StudioPhoto $photo): bool
    {
        try {
            if ($photo->status === 'queued') {
                if ($photo->kind === 'twilight') {
                    $parent = StudioPhoto::query()->where('listing_ulid', $photo->listing_ulid)->where('media_id', $photo->media_id)->where('kind', 'enhanced')->first();
                    if ($parent === null || $parent->status === 'failed') {
                        $this->fail($photo, 'The twilight needs the enhanced photo first, and that didn\'t work.');

                        return true;
                    }
                    if ($parent->status !== 'ready' || $parent->provider_ref === null) {
                        return false; // wait for it
                    }
                    $ref = $this->enhancer->twilight($parent->provider_ref);
                } else {
                    $ref = $this->enhancer->start($this->original($photo), 'rightally-'.strtolower($photo->ulid));
                }
                $photo->forceFill(['provider_ref' => $ref, 'status' => 'processing', 'attempts' => $photo->attempts + 1])->save();

                return false;
            }
            $state = $this->enhancer->status((string) $photo->provider_ref);
            if ($state === 'processing') {
                return false;
            }
            if ($state === 'failed') {
                $this->fail($photo, 'The enhancement service couldn\'t improve this photo.');

                return true;
            }
            $path = 'studio/'.strtolower($this->tenants->current()->ulid).'/photos/'.strtolower($photo->ulid).'.jpg';
            $disk = Storage::disk('public');
            $disk->put($path, $this->enhancer->fetch((string) $photo->provider_ref), ['visibility' => 'public']);
            $upscaled = false;
            if ($this->upscaler->ready() && ($big = $this->upscaler->upscale(self::link($path))) !== null) {
                $disk->put($path, $big, ['visibility' => 'public']);
                $upscaled = true;
            }
            $photo->forceFill(['path' => $path, 'upscaled' => $upscaled, 'status' => 'ready', 'error' => null])->save();

            return true;
        } catch (Throwable $e) {
            report($e);
            $photo->forceFill(['attempts' => $photo->attempts + 1])->save();
            if ($photo->attempts >= 3) {
                $this->fail($photo, 'The enhancement service didn\'t answer. Please try again later.');

                return true;
            }

            return false;
        }
    }

    /** Marks an enhancement failed and gives back what it cost (the allowance item, or the credits). */
    public function fail(StudioPhoto $photo, string $why): void
    {
        $photo->forceFill(['status' => 'failed', 'error' => mb_substr($why, 0, 300)])->save();
        $user = User::query()->find($photo->user_id);
        if ($user !== null && $photo->paid === 'allowance') {
            $this->plans->giveBack($user, 'enhance');
        } elseif ($photo->paid === 'credits') {
            app(CreditLedger::class)->refund('studio:enhance:'.$photo->charge_ref, 'Photo enhancement failed');
        }
        $photo->forceFill(['paid' => null])->save();
    }

    private function listing(User $user, string $ulid): Listing
    {
        if (app(ListingAds::class)->listing($user, $ulid) === null) {
            throw ValidationException::withMessages(['listing' => 'Choose one of your listings.']);
        }

        return Listing::query()->where('ulid', $ulid)->firstOrFail();
    }

    /** The MLS photo at its largest stored size, as a JPEG for the service. */
    private function original(StudioPhoto $photo): string
    {
        $m = ListingMedia::query()->findOrFail($photo->media_id);
        $hero = (int) (config('listings.sizes')['hero'] ?? 1600);
        $bytes = (string) Storage::disk((string) config('listings.media_disk', 'public'))->get($m->storage_path.'-'.$hero.'.webp');
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw ValidationException::withMessages(['photos' => 'This photo can\'t be read.']);
        }
        ob_start();
        imagejpeg($img, null, 95);

        return (string) ob_get_clean();
    }

    private static function link(string $path): string
    {
        $cdn = config('listings.cdn_url');

        return is_string($cdn) && $cdn !== '' ? rtrim($cdn, '/').'/'.$path : Storage::disk('public')->url($path);
    }
}
