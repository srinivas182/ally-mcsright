<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Sites\Reviews\Reviews;

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
        $this->reviews($connection, $http, $locations);

        return ['created' => 0, 'updated' => count($locations), 'skipped' => 0, 'cursor' => null, 'note' => $locations === [] ? 'No business locations found on this Google account.' : null];
    }

    /**
     * C8a (specs/connect/20): each location's newest reviews, kept with their source and date for the websites;
     * replies are posted back to Google from Reviews.
     *
     * @param  list<array{id: string, name: string}>  $locations
     */
    private function reviews(ConnectionData $connection, PendingRequest $http, array $locations): void
    {
        $stars = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
        $rows = [];
        foreach (array_slice($locations, 0, 10) as $loc) {
            $r = $http->get(self::POSTS.$loc['id'].'/reviews', ['pageSize' => 50, 'orderBy' => 'updateTime desc']);
            foreach ((array) ($r->successful() ? $r->json('reviews', []) : []) as $rv) {
                if (! is_array($rv) || ! is_string($rv['name'] ?? null) || preg_match('#^accounts/\d+/locations/\d+/reviews/[A-Za-z0-9_-]+$#', $rv['name']) !== 1) {
                    continue;
                }
                $rows[] = ['external_id' => $rv['name'], 'author' => ($rv['reviewer']['isAnonymous'] ?? false) === true ? null : (is_string($rv['reviewer']['displayName'] ?? null) ? $rv['reviewer']['displayName'] : null),
                    'rating' => $stars[$rv['starRating'] ?? ''] ?? null, 'text' => is_string($rv['comment'] ?? null) ? $rv['comment'] : null, 'url' => null,
                    'reviewed_at' => CarbonImmutable::parse((string) ($rv['createTime'] ?? 'now')), 'reply' => is_string($rv['reviewReply']['comment'] ?? null) ? $rv['reviewReply']['comment'] : null];
            }
        }
        app(Reviews::class)->save('google', $connection->ownerUserId, $connection->id, $rows);
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
