<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * Google Business Profile (specs/connect/19): the shared Google sign-in with business.manage. Once a day the
 * person's accounts and locations are read; posts are "What's new" updates with a Learn more button and a photo.
 */
final class GoogleBusiness implements Connector, SocialChannel
{
    public const ACCOUNTS = 'https://mybusinessaccountmanagement.googleapis.com/v1/';

    public const INFO = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    public const POSTS = 'https://mybusiness.googleapis.com/v4/';

    public function __construct(private readonly ConnectorKit $kit) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Google Business Profile';
    }

    public function sync(ConnectionData $connection): array
    {
        $http = Http::withToken($this->kit->accessToken($connection))->acceptJson()->timeout(20);
        $r = $http->get(self::ACCOUNTS.'accounts', ['pageSize' => 20]);
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Google asked you to sign in again. Please connect Google Business Profile again.');
        }
        $r->throw();
        $locations = [];
        foreach (array_slice((array) $r->json('accounts', []), 0, 20) as $a) {
            if (! is_array($a) || ! is_string($a['name'] ?? null) || preg_match('#^accounts/\d+$#', $a['name']) !== 1) {
                continue;
            }
            $l = $http->get(self::INFO.$a['name'].'/locations', ['readMask' => 'name,title', 'pageSize' => 100]);
            foreach ((array) ($l->successful() ? $l->json('locations', []) : []) as $loc) {
                if (is_array($loc) && is_string($loc['name'] ?? null) && preg_match('#^locations/\d+$#', $loc['name']) === 1) {
                    $locations[] = ['id' => $a['name'].'/'.$loc['name'], 'name' => mb_substr((string) ($loc['title'] ?? 'Location'), 0, 100)];
                }
            }
        }
        $this->kit->saveSettings($connection, ['locations' => $locations, 'interval_minutes' => 1440]);

        return ['created' => 0, 'updated' => count($locations), 'skipped' => 0, 'cursor' => null, 'note' => $locations === [] ? 'No business locations found on this Google account.' : null];
    }

    public function places(ConnectionData $connection): array
    {
        $out = [];
        foreach ((array) ($connection->settings['locations'] ?? []) as $l) {
            if (is_array($l) && is_string($l['id'] ?? null)) {
                $out[] = ['channel' => 'google', 'id' => $l['id'], 'name' => (string) ($l['name'] ?? $l['id'])];
            }
        }

        return $out;
    }

    public function post(ConnectionData $connection, string $channel, string $target, array $content): array
    {
        if (preg_match('#^accounts/\d+/locations/\d+$#', $target) !== 1) {
            throw new PostFailed('This Google location is no longer available.');
        }
        $body = array_filter(['languageCode' => 'en-US', 'summary' => mb_substr($content['text'], 0, 1500), 'topicType' => 'STANDARD',
            'callToAction' => $content['link'] !== null ? ['actionType' => 'LEARN_MORE', 'url' => $content['link']] : null,
            'media' => $content['image'] !== null ? [['mediaFormat' => 'PHOTO', 'sourceUrl' => $content['image']]] : null]);
        $r = Http::withToken($this->kit->accessToken($connection))->acceptJson()->timeout(30)->post(self::POSTS.$target.'/localPosts', $body);
        if (! $r->successful() || ! is_string($r->json('name'))) {
            throw PostFailed::from($r, 'Google');
        }
        $url = $r->json('searchUrl');

        return ['id' => (string) $r->json('name'), 'url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null];
    }
}
