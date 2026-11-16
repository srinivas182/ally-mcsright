<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Modules\Core\Connect\ConnectionData;

/** A channel that shares a post's numbers (specs/grow/13, SA4). */
interface SocialMetrics
{
    /**
     * The numbers so far for one posted place, or null when the network doesn't share them.
     *
     * @return array{reach: int, views: int, likes: int, comments: int, shares: int, saves: int, clicks: int}|null
     */
    public function metrics(ConnectionData $connection, string $channel, string $target, string $postId): ?array;
}
