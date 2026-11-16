<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;

/**
 * YouTube (specs/grow/13, SA3b): the shared Google sign-in with upload permission only (youtube.upload). Ally Studio
 * Reels go up as Shorts (vertical, under three minutes) and listing tours as videos with chapters, through the
 * resumable upload. Uploads are private until RightAlly's Google project passes YouTube's API audit; then HQ sets
 * STUDIO_YOUTUBE_PRIVACY=public.
 */
final class YouTube implements Connector, SocialChannel
{
    public const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    public function __construct(private readonly ConnectorKit $kit) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'YouTube';
    }

    public function sync(ConnectionData $connection): array
    {
        $this->kit->accessToken($connection); // keeps the sign-in fresh; upload permission can't read the channel name
        $this->kit->saveSettings($connection, ['channel' => ['id' => 'mine', 'name' => 'YouTube channel'], 'interval_minutes' => 1440]);

        return ['created' => 0, 'updated' => 1, 'skipped' => 0, 'cursor' => null, 'note' => null];
    }

    public function places(ConnectionData $connection): array
    {
        return [['channel' => 'youtube', 'id' => 'mine', 'name' => (string) ($connection->settings['channel']['name'] ?? 'YouTube channel')]];
    }

    public function post(ConnectionData $connection, string $channel, string $target, array $content): array
    {
        if ($content['video'] === null) {
            throw new PostFailed('YouTube takes videos: post a Reel or a listing tour.');
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $content['text']))));
        $title = mb_substr((string) preg_replace('/[<>]/', '', $lines[0] ?? 'Listing tour'), 0, 100);
        $description = mb_substr((string) preg_replace('/[<>]/', '', $content['text'].($content['link'] !== null ? "\n\n".$content['link'] : '')), 0, 4900);
        $token = $this->kit->accessToken($connection);
        $video = Http::timeout(120)->get($content['video']);
        if (! $video->successful()) {
            throw new PostFailed('YouTube: the video could not be read.');
        }
        $init = Http::withToken($token)->withHeaders(['X-Upload-Content-Type' => 'video/mp4', 'X-Upload-Content-Length' => (string) strlen($video->body())])
            ->acceptJson()->timeout(30)->post(self::UPLOAD.'?uploadType=resumable&part=snippet,status', [
                'snippet' => ['title' => $title, 'description' => $description, 'categoryId' => '22'],
                'status' => ['privacyStatus' => config('studio.youtube_privacy', 'private') === 'public' ? 'public' : 'private', 'selfDeclaredMadeForKids' => false],
            ]);
        $session = (string) $init->header('Location');
        if (! $init->successful() || ! str_starts_with($session, 'https://')) {
            throw PostFailed::from($init, 'YouTube');
        }
        $r = Http::withToken($token)->withBody($video->body(), 'video/mp4')->timeout(600)->put($session);
        $id = (string) ($r->json('id') ?? '');
        if (! $r->successful() || $id === '') {
            throw PostFailed::from($r, 'YouTube');
        }

        return ['id' => $id, 'url' => 'https://www.youtube.com/watch?v='.$id];
    }
}
