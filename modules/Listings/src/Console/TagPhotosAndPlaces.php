<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use Illuminate\Console\Command;
use Modules\Listings\Media\PhotoTags;
use Modules\Listings\Places\NearbyPlaces;

/**
 * C03: tags waiting listing photos and looks up nearby schools and transit, within each day's allowance
 * (PhotoTags::DAILY_*, NearbyPlaces::DAILY_*). Does nothing for a service that is Off.
 */
final class TagPhotosAndPlaces extends Command
{
    protected $signature = 'listings:photos-and-places {--limit= : At most this many photos and listings this run}';

    protected $description = 'Tag listing photos and look up nearby schools and transit (Outside services → Photo tagging, Nearby schools and transit).';

    public function handle(PhotoTags $tags, NearbyPlaces $places): int
    {
        $limit = is_numeric($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $t = $tags->run($limit);
        $this->line($t['mode'] === null ? 'Photo tagging is off.' : "Photo tagging ({$t['mode']}): {$t['photos']} photos in {$t['listings']} listings.");
        $p = $places->run($limit);
        $this->line($p['mode'] === null ? 'Nearby schools and transit is off.' : "Nearby places ({$p['mode']}): {$p['listings']} listings, {$p['places']} places.");

        return self::SUCCESS;
    }
}
