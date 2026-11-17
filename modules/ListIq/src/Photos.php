<?php

declare(strict_types=1);

namespace Modules\ListIq;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Studio\Enhance\PhotoEnhancer;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Tenancy\TenantContext;
use Modules\ListIq\Jobs\ProcessPhoto;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Models\Prep;
use Modules\ListIq\Staging\ReplicateStager;
use Modules\ListIq\Staging\VirtualStager;
use Throwable;

/**
 * A listing's photos (LI1, specs/listings/02): uploaded room by room from the guided shoot, put in the order buyers
 * like, captioned by room (the agent can change any caption), enhanced, or virtually staged. The original is never
 * changed. A staged photo always carries a "Virtually staged" label in the image, and the original stays in the set
 * so the MLS can show both.
 */
final class Photos
{
    public const LABEL = 'Virtually staged';

    public const MAX_UPLOAD = 30;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function upload(Prep $prep, string $room, array $files): int
    {
        if (! array_key_exists($room, Shoot::ROOMS)) {
            throw ValidationException::withMessages(['room' => 'Choose the room.']);
        }
        if (count($files) === 0 || count($files) > self::MAX_UPLOAD) {
            throw ValidationException::withMessages(['photos' => 'Choose 1 to '.self::MAX_UPLOAD.' photos.']);
        }
        $n = 0;
        foreach ($files as $file) {
            $img = @imagecreatefromstring((string) $file->get());
            if ($img === false) {
                continue;
            }
            $path = $this->store($prep, self::jpeg($img));
            Photo::query()->create(['listing_id' => $prep->id, 'kind' => 'original', 'room' => $room, 'path' => $path, 'caption' => Shoot::label($room), 'status' => 'ready',
                'position' => 1000 + Photo::query()->where('listing_id', $prep->id)->count()]);
            $n++;
        }
        if ($n === 0) {
            throw ValidationException::withMessages(['photos' => 'Those files aren\'t photos we can read (use JPEG, PNG or HEIC saved as JPEG).']);
        }
        $this->autoOrder($prep);

        return $n;
    }

    /** Rooms in the shot-list order, then in the order they were taken; staged versions right after their original. */
    public function autoOrder(Prep $prep): void
    {
        $photos = Photo::query()->where('listing_id', $prep->id)->get();
        $sorted = $photos->sortBy(fn (Photo $p): string => sprintf('%02d-%06d-%d', Shoot::order($p->room), $p->parent_id ?? $p->id, $p->parent_id === null ? 0 : 1))->values();
        foreach ($sorted as $i => $p) {
            if ($p->position !== $i) {
                $p->forceFill(['position' => $i])->save();
            }
        }
    }

    /**
     * @param  list<string>  $ulids  in the order the agent wants
     */
    public function reorder(Prep $prep, array $ulids): void
    {
        foreach ($ulids as $i => $ulid) {
            Photo::query()->where('listing_id', $prep->id)->where('ulid', $ulid)->update(['position' => $i]);
        }
    }

    public function edit(Photo $photo, ?string $caption, ?bool $use): void
    {
        if ($caption !== null) {
            $photo->caption = mb_substr(trim(strip_tags($caption)), 0, 160);
        }
        if ($use !== null) {
            $photo->use = $use;
        }
        $photo->save();
    }

    /** Enhance (light, colour, straight lines, sky) with Ally Studio's service. */
    public function enhance(Photo $photo): Photo
    {
        if (! app(PhotoEnhancer::class)->ready()) {
            throw ValidationException::withMessages(['photo' => 'Photo enhancement isn\'t switched on yet.']);
        }
        $copy = Photo::query()->create(['listing_id' => $photo->listing_id, 'parent_id' => $photo->id, 'kind' => 'enhanced', 'room' => $photo->room, 'caption' => $photo->caption,
            'status' => 'queued', 'position' => $photo->position, 'use' => false]);
        ProcessPhoto::dispatch($copy->id);

        return $copy;
    }

    /** Virtually stage an empty room, in a style; paid with credits (refunded if it doesn't work). */
    public function stage(Photo $photo, string $style, User $by): Photo
    {
        if (! array_key_exists($style, ReplicateStager::STYLES)) {
            throw ValidationException::withMessages(['style' => 'Choose a style.']);
        }
        if ($photo->kind !== 'original') {
            throw ValidationException::withMessages(['photo' => 'Stage the original photo.']);
        }
        if (! app(VirtualStager::class)->ready()) {
            throw ValidationException::withMessages(['photo' => 'Virtual staging isn\'t switched on yet. Please ask RightAlly support.']);
        }
        $ref = 'listiq.staging:'.Str::ulid();
        try {
            app(CreditLedger::class)->charge('listiq.staging', $by->id, $ref);
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }
        $copy = Photo::query()->create(['listing_id' => $photo->listing_id, 'parent_id' => $photo->id, 'kind' => 'staged', 'room' => $photo->room, 'style' => $style,
            'caption' => trim((string) $photo->caption).' ('.self::LABEL.')', 'status' => 'queued', 'position' => $photo->position, 'use' => false, 'charge_ref' => $ref]);
        ProcessPhoto::dispatch($copy->id);

        return $copy;
    }

    /**
     * One step of the work behind an enhanced or staged photo. True when finished (ready or failed).
     */
    public function step(Photo $photo): bool
    {
        $parent = $photo->parent_id !== null ? Photo::query()->find($photo->parent_id) : null;
        if ($parent === null || $parent->path === null) {
            return $this->fail($photo, 'The original photo is gone.');
        }
        $prep = Prep::query()->find($photo->listing_id);
        try {
            if ($photo->kind === 'staged') {
                $bytes = app(VirtualStager::class)->stage(Storage::disk('public')->url($parent->path), $photo->room, (string) $photo->style);
                if ($bytes === null) {
                    return $this->fail($photo, 'Staging didn\'t work on this photo. Your credits were returned.');
                }
                $img = @imagecreatefromstring($bytes);
                if ($img === false || $prep === null) {
                    return $this->fail($photo, 'The staged photo couldn\'t be read. Your credits were returned.');
                }
                self::label($img, self::LABEL);
                $photo->forceFill(['path' => $this->store($prep, self::jpeg($img)), 'status' => 'ready', 'use' => true])->save();

                return true;
            }
            $enhancer = app(PhotoEnhancer::class);
            if ($photo->status === 'queued') {
                $photo->forceFill(['provider_ref' => $enhancer->start((string) Storage::disk('public')->get($parent->path), 'listiq-'.strtolower($photo->ulid)), 'status' => 'processing'])->save();

                return false;
            }
            $state = $enhancer->status((string) $photo->provider_ref);
            if ($state === 'failed') {
                return $this->fail($photo, 'Enhancement didn\'t work on this photo.');
            }
            if ($state !== 'ready' || $prep === null) {
                return false;
            }
            $photo->forceFill(['path' => $this->store($prep, $enhancer->fetch((string) $photo->provider_ref)), 'status' => 'ready', 'use' => true])->save();
            $parent->forceFill(['use' => false])->save(); // the enhanced one replaces it in the set

            return true;
        } catch (Throwable $e) {
            report($e);

            return $this->fail($photo, 'That didn\'t work. Please try again.');
        } finally {
            if ($prep !== null) {
                $this->autoOrder($prep);
            }
        }
    }

    public function fail(Photo $photo, string $why): bool
    {
        $photo->forceFill(['status' => 'failed', 'error' => mb_substr($why, 0, 200)])->save();
        if ($photo->charge_ref !== null) {
            app(CreditLedger::class)->refund($photo->charge_ref, 'Virtual staging did not work');
        }

        return true;
    }

    public static function url(?string $path): ?string
    {
        return $path !== null ? Storage::disk('public')->url($path) : null;
    }

    /** "Virtually staged", in the bottom-left corner, on a dark band so it reads on any photo. */
    public static function label(\GdImage $img, string $text): void
    {
        $h = imagesy($img);
        $font = 5;
        $w = imagefontwidth($font) * strlen($text) + 20;
        $band = imagefontheight($font) + 14;
        $black = imagecolorallocatealpha($img, 0, 0, 0, 40);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 10, $h - $band - 10, 10 + $w, $h - 10, (int) $black);
        imagestring($img, $font, 20, $h - $band - 3, $text, (int) $white);
    }

    private static function jpeg(\GdImage $img): string
    {
        // Large enough for any MLS, small enough to upload quickly.
        $w = imagesx($img);
        if ($w > 2400) {
            $scaled = imagescale($img, 2400);
            if ($scaled !== false) {
                $img = $scaled;
            }
        }
        ob_start();
        imagejpeg($img, null, 86);

        return (string) ob_get_clean();
    }

    private function store(Prep $prep, string $bytes): string
    {
        $path = 'listiq/'.$this->tenants->id().'/'.$prep->ulid.'/'.Str::random(24).'.jpg';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }
}
