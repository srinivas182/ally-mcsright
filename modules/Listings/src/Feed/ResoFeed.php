<?php

declare(strict_types=1);

namespace Modules\Listings\Feed;

use Modules\Listings\Models\MlsSource;

/** Reads an MLS over the RESO Web API (or a provider's flavour of it). */
interface ResoFeed
{
    /**
     * Records changed after $since (ISO 8601), oldest first; or the page at $next.
     */
    public function changes(MlsSource $source, ?string $since, ?string $next, int $top = 200): FeedPage;

    /**
     * Every listing key the MLS currently shows as on the market (for removal checks).
     *
     * @return list<string>
     */
    public function activeKeys(MlsSource $source): array;

    /** Checks the credentials and endpoint; returns a short error, or null when it works. */
    public function test(MlsSource $source): ?string;
}
