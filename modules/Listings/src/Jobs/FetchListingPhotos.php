<?php

declare(strict_types=1);

namespace Modules\Listings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;

/** Downloads a listing's waiting photos; anything that failed is tried again later (back-off, up to 24 hours). */
final class FetchListingPhotos implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $listingId) {}

    public function handle(Photos $photos): void
    {
        $listing = Listing::query()->find($this->listingId);
        if ($listing === null) {
            return;
        }
        $retry = false;
        foreach (ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'pending')->orderBy('position')->get() as $media) {
            $retry = ! $photos->store($media, $listing) || $retry;
        }
        if ($retry && config('queue.default') !== 'sync') {
            $attempts = (int) ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'pending')->max('attempts');
            self::dispatch($listing->id)->delay(now()->addMinutes(min(240, 2 ** min($attempts, 8))));
        }
    }
}
