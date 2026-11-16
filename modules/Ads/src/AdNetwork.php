<?php

declare(strict_types=1);

namespace Modules\Ads;

use Modules\Ads\Models\Campaign;

/**
 * Where a listing ad runs (specs/ads/01, 05): Meta (Facebook and Instagram) or Google (search and YouTube). Results,
 * stop-loss, end dates and Boost refunds work the same whatever the network.
 */
interface AdNetwork
{
    /** Creates the whole ad, or nothing. */
    public function publish(Campaign $c, string $token): void;

    public function setStatus(Campaign $c, string $token, bool $active): void;

    /** @return array{spend_cents: int, impressions: int, clicks: int, leads: int}|null all-time numbers; null if never published */
    public function results(Campaign $c, string $token): ?array;
}
