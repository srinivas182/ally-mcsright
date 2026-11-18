<?php

declare(strict_types=1);

namespace Modules\Listings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Listings\Media\PhotoTags;
use Modules\Listings\Places\NearbyPlaces;

/** C03: a small run of photo tagging and nearby places, started from HQ to check results (Test or Live). */
final class TagPhotosAndPlaces implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $limit = 10) {}

    public function handle(PhotoTags $tags, NearbyPlaces $places): void
    {
        $tags->run($this->limit);
        $places->run($this->limit);
    }
}
