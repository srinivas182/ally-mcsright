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
        if ($content['link'] !== null) {
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

    /** LinkedIn's "little text" format reserves these characters; escaped, they show as typed. */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $text);
    }
}
