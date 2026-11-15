<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialPosts;

/** Sends a social post that is due now (specs/connect/19). Not retried: each place shows its own result. */
final class PublishSocialPost implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $postId)
    {
        $this->afterCommit();
    }

    public function handle(SocialPosts $posts): void
    {
        $post = SocialPost::query()->find($this->postId);
        if ($post !== null) {
            $posts->publish($post);
        }
    }
}
