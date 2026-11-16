<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * LinkedIn (specs/connect/19): posts to the person's own profile ("Share on LinkedIn", w_member_social). The
 * person's member id is read once a day. A link becomes an article card. Company pages need LinkedIn's Community
 * Management API (later).
 */
final class LinkedIn implements Connector, SocialChannel
{
    public const API = 'https://api.linkedin.com/';

    /** LinkedIn's versioned REST API (YYYYMM); each version is supported for about a year. */
    public const VERSION = '202609';

    public function __construct(private readonly ConnectorKit $kit) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'LinkedIn';
    }

    public function sync(ConnectionData $connection): array
    {
        $r = Http::withToken($this->kit->accessToken($connection))->acceptJson()->timeout(20)->get(self::API.'v2/userinfo');
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('LinkedIn asked you to sign in again (LinkedIn sign-ins last 60 days). Please connect LinkedIn again.');
        }
        $r->throw();
        $this->kit->saveSettings($connection, ['person' => ['id' => (string) $r->json('sub'), 'name' => mb_substr((string) ($r->json('name') ?? 'LinkedIn profile'), 0, 100)],
            'interval_minutes' => 1440]);

        return ['created' => 0, 'updated' => 1, 'skipped' => 0, 'cursor' => null, 'note' => null];
    }

    public function places(ConnectionData $connection): array
    {
        $p = $connection->settings['person'] ?? null;

        return is_array($p) && is_string($p['id'] ?? null) && $p['id'] !== '' ? [['channel' => 'linkedin', 'id' => $p['id'], 'name' => (string) ($p['name'] ?? 'LinkedIn profile')]] : [];
    }

    public function post(ConnectionData $connection, string $channel, string $target, array $content): array
    {
        $body = ['author' => 'urn:li:person:'.$target, 'commentary' => self::escape(mb_substr($content['text'], 0, 3000)), 'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false];
        if (count($content['images']) >= 2) {
            // A multi-image post (2 to 20 images): each uploaded through the Images API first; the link goes in the words.
            $ids = array_map(fn (string $url): string => $this->upload($connection, $target, $url), array_slice($content['images'], 0, 20));
            $body['content'] = ['multiImage' => ['images' => array_map(fn (string $id, int $i): array => ['id' => $id, 'altText' => 'Image '.($i + 1).' of '.count($ids)],
                $ids, array_keys($ids))]];
            if ($content['link'] !== null) {
                $body['commentary'] = self::escape(mb_substr($content['text']."\n\n".$content['link'], 0, 3000));
            }
        } elseif ($content['link'] !== null) {
            $body['content'] = ['article' => ['source' => $content['link'], 'title' => mb_substr($content['title'], 0, 200)]];
        }
        $r = Http::withToken($this->kit->accessToken($connection))->withHeaders(['LinkedIn-Version' => self::VERSION, 'X-Restli-Protocol-Version' => '2.0.0'])
            ->acceptJson()->timeout(30)->post(self::API.'rest/posts', $body);
        $urn = (string) $r->header('x-restli-id');
        if (! $r->successful() || $urn === '') {
            throw PostFailed::from($r, 'LinkedIn');
        }

        return ['id' => $urn, 'url' => 'https://www.linkedin.com/feed/update/'.$urn];
    }

    /** Uploads one image for the person (Images API) and returns its urn:li:image. */
    private function upload(ConnectionData $connection, string $person, string $url): string
    {
        $token = $this->kit->accessToken($connection);
        $init = Http::withToken($token)->withHeaders(['LinkedIn-Version' => self::VERSION, 'X-Restli-Protocol-Version' => '2.0.0'])->acceptJson()->timeout(30)
            ->post(self::API.'rest/images?action=initializeUpload', ['initializeUploadRequest' => ['owner' => 'urn:li:person:'.$person]]);
        $upload = (string) ($init->json('value.uploadUrl') ?? '');
        $image = (string) ($init->json('value.image') ?? '');
        if (! $init->successful() || ! str_starts_with($upload, 'https://') || $image === '') {
            throw PostFailed::from($init, 'LinkedIn');
        }
        $bytes = Http::timeout(60)->get($url);
        if (! $bytes->successful()) {
            throw new PostFailed('LinkedIn: one of the carousel images could not be read.');
        }
        $put = Http::withToken($token)->withBody($bytes->body(), 'image/jpeg')->timeout(120)->put($upload);
        if (! $put->successful()) {
            throw PostFailed::from($put, 'LinkedIn');
        }

        return $image;
    }

    /** LinkedIn's "little text" format reserves these characters; escaped, they show as typed. */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $text);
    }
}
