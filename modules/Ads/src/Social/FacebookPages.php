<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Illuminate\Support\Facades\Http;
use Modules\Ads\Meta\MetaApi;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * Facebook Pages and Instagram (specs/connect/19): one Meta sign-in (the Facebook Lead Ads app). Once a day the
 * person's Pages, their Page tokens (kept encrypted) and the Instagram business accounts linked to them are read.
 * Posts go to a Page's feed (with the link as a preview, or a photo) and to Instagram (container, then publish).
 */
final class FacebookPages implements Connector, SocialChannel
{
    public function __construct(private readonly ConnectorKit $kit) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Facebook Pages';
    }

    public function sync(ConnectionData $connection): array
    {
        $token = $this->kit->accessToken($connection);
        if (! isset($connection->credentials['long_lived'])) {
            $keys = $this->kit->secrets('facebook_leads');
            $r = Http::timeout(20)->get(MetaApi::GRAPH.'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $keys['client_id'] ?? '',
                'client_secret' => $keys['client_secret'] ?? '', 'fb_exchange_token' => $token]);
            if (is_string($r->json('access_token'))) {
                $token = (string) $r->json('access_token');
                $this->kit->saveCredentials($connection, ['access_token' => $token, 'expires_at' => (string) (time() + (int) ($r->json('expires_in') ?? 5_184_000)), 'long_lived' => '1']);
            }
        }
        $r = Http::withToken($token)->timeout(20)->get(MetaApi::GRAPH.'me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100]);
        if (in_array($r->status(), [400, 401, 403], true) && (int) $r->json('error.code') === 190) {
            throw new NeedsReconnect('Facebook asked you to sign in again. Please connect Facebook Pages again.');
        }
        $r->throw();
        $tokens = [];
        $pages = [];
        $instagram = [];
        foreach ((array) $r->json('data', []) as $p) {
            if (! is_array($p) || ! is_string($p['id'] ?? null) || ! is_string($p['access_token'] ?? null)) {
                continue;
            }
            $tokens[$p['id']] = $p['access_token'];
            $pages[] = ['id' => $p['id'], 'name' => mb_substr((string) ($p['name'] ?? 'Page'), 0, 100)];
            $ig = $p['instagram_business_account'] ?? null;
            if (is_array($ig) && is_string($ig['id'] ?? null)) {
                $instagram[] = ['id' => $ig['id'], 'name' => '@'.mb_substr((string) ($ig['username'] ?? 'instagram'), 0, 60), 'page' => $p['id']];
            }
        }
        $this->kit->saveCredentials($connection, ['pages' => (string) json_encode($tokens)]);
        $this->kit->saveSettings($connection, ['pages' => $pages, 'instagram' => $instagram, 'interval_minutes' => 1440]);

        return ['created' => 0, 'updated' => count($pages) + count($instagram), 'skipped' => 0, 'cursor' => null, 'note' => null];
    }

    public function places(ConnectionData $connection): array
    {
        $out = [];
        foreach (['facebook' => 'pages', 'instagram' => 'instagram'] as $channel => $key) {
            foreach ((array) ($connection->settings[$key] ?? []) as $p) {
                if (is_array($p) && is_string($p['id'] ?? null)) {
                    $out[] = ['channel' => $channel, 'id' => $p['id'], 'name' => (string) ($p['name'] ?? $p['id'])];
                }
            }
        }

        return $out;
    }

    public function post(ConnectionData $connection, string $channel, string $target, array $content): array
    {
        if ($channel === 'instagram') {
            return $this->instagram($connection, $target, $content);
        }
        $token = $this->pageToken($connection, $target);
        $http = Http::asForm()->timeout(30);
        if ($content['link'] === null && $content['image'] !== null) {
            $r = $http->post(MetaApi::GRAPH.$target.'/photos', ['url' => $content['image'], 'caption' => $content['text'], 'access_token' => $token]);
            $id = (string) ($r->json('post_id') ?? $r->json('id') ?? '');
        } else {
            $r = $http->post(MetaApi::GRAPH.$target.'/feed', array_filter(['message' => $content['text'], 'link' => $content['link'], 'access_token' => $token]));
            $id = (string) ($r->json('id') ?? '');
        }
        if (! $r->successful() || $id === '') {
            throw PostFailed::from($r, 'Facebook');
        }

        return ['id' => $id, 'url' => 'https://www.facebook.com/'.$id];
    }

    /**
     * @param  array{text: string, link: string|null, image: string|null, title: string}  $content
     * @return array{id: string, url: string|null}
     */
    private function instagram(ConnectionData $connection, string $account, array $content): array
    {
        $page = null;
        foreach ((array) ($connection->settings['instagram'] ?? []) as $ig) {
            if (is_array($ig) && ($ig['id'] ?? null) === $account) {
                $page = (string) ($ig['page'] ?? '');
            }
        }
        if ($page === null || $content['image'] === null) {
            throw new PostFailed($page === null ? 'This Instagram account is no longer linked to your Facebook Page.' : 'Instagram needs a photo.');
        }
        $token = $this->pageToken($connection, $page);
        // Instagram captions can't hold links, so the link is written out.
        $caption = mb_substr($content['text'].($content['link'] !== null ? "\n\n".$content['link'] : ''), 0, 2200);
        $made = Http::asForm()->timeout(60)->post(MetaApi::GRAPH.$account.'/media', ['image_url' => $content['image'], 'caption' => $caption, 'access_token' => $token]);
        if (! $made->successful() || ! is_string($made->json('id'))) {
            throw PostFailed::from($made, 'Instagram');
        }
        $r = Http::asForm()->timeout(60)->post(MetaApi::GRAPH.$account.'/media_publish', ['creation_id' => (string) $made->json('id'), 'access_token' => $token]);
        if (! $r->successful() || ! is_string($r->json('id'))) {
            throw PostFailed::from($r, 'Instagram');
        }
        $link = Http::timeout(10)->get(MetaApi::GRAPH.$r->json('id'), ['fields' => 'permalink', 'access_token' => $token])->json('permalink');

        return ['id' => (string) $r->json('id'), 'url' => is_string($link) && str_starts_with($link, 'https://') ? $link : null];
    }

    private function pageToken(ConnectionData $connection, string $page): string
    {
        $tokens = json_decode((string) ($connection->credentials['pages'] ?? '{}'), true);
        $token = is_array($tokens) ? ($tokens[$page] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : throw new PostFailed('RightAlly can no longer post to this Page. Connect Facebook Pages again.');
    }
}
