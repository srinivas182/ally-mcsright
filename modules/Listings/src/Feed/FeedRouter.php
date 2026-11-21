<?php

declare(strict_types=1);

namespace Modules\Listings\Feed;

use Modules\Listings\Models\MlsSource;

/** IDX3: sends each feed to its reader: Repliers' own API, or the RESO Web API for every other provider. */
final class FeedRouter implements ResoFeed
{
    public function __construct(private readonly ResoWebApi $reso, private readonly RepliersFeed $repliers) {}

    public function changes(MlsSource $source, ?string $since, ?string $next, int $top = 200): FeedPage
    {
        return $this->for($source)->changes($source, $since, $next, $top);
    }

    public function activeKeys(MlsSource $source): array
    {
        return $this->for($source)->activeKeys($source);
    }

    public function test(MlsSource $source): ?string
    {
        return $this->for($source)->test($source);
    }

    private function for(MlsSource $source): ResoFeed
    {
        return $source->provider === 'repliers' ? $this->repliers : $this->reso;
    }
}
