<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Modules\Core\Connect\ConnectionData;

/** A connected app that social posts can go to (specs/connect/19). */
interface SocialChannel
{
    /**
     * The places this connection can post to, from its settings (refreshed by the daily sync).
     *
     * @return list<array{channel: string, id: string, name: string}>
     */
    public function places(ConnectionData $connection): array;

    /**
     * Post once to one place. With two or more images it is a carousel (in order); with a video,
     * a Reel where the network takes one (Instagram, Facebook) and the cover image elsewhere. Throws PostFailed with a reason people can act on.
     *
     * @param  array{text: string, link: string|null, image: string|null, images: list<string>, video: string|null, title: string, synthetic?: bool}  $content
     * @return array{id: string, url: string|null}
     */
    public function post(ConnectionData $connection, string $channel, string $target, array $content): array;
}
