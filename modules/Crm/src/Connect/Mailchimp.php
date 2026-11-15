<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Connect\ReceivesWebhooks;

/**
 * Mailchimp (specs/connect/15, C7a): Marketing API 3.0. OAuth tokens don't expire; the account's API address
 * (data centre) comes from the OAuth metadata endpoint and is kept with the connection. Members are upserted by
 * the MD5 of the lower-cased email; unsubscribes and cleaned addresses arrive by the audience's webhook, which
 * Mailchimp checks with a GET first and then sends as a form post.
 */
final class Mailchimp implements EmailApp, ReceivesWebhooks
{
    public const METADATA = 'https://login.mailchimp.com/oauth2/metadata';

    public function __construct(private readonly ConnectorKit $kit) {}

    public function name(): string
    {
        return 'Mailchimp';
    }

    public function verify(array $credentials, array $settings): string
    {
        return 'Mailchimp'; // connected with one click (OAuth)
    }

    public function sync(ConnectionData $connection): array
    {
        $this->hook($connection);

        return app(EmailMarketing::class)->run($connection, $this);
    }

    public function audiences(ConnectionData $connection): array
    {
        $r = self::check($this->http($connection)->get('lists', ['count' => 100, 'fields' => 'lists.id,lists.name,lists.stats.member_count']));
        $out = [];
        foreach ((array) $r->json('lists', []) as $l) {
            $out[] = ['id' => (string) ($l['id'] ?? ''), 'name' => (string) ($l['name'] ?? ''), 'members' => isset($l['stats']['member_count']) ? (int) $l['stats']['member_count'] : null];
        }

        return $out;
    }

    public function upsert(ConnectionData $connection, string $audience, array $person): void
    {
        $hash = hash('md5', mb_strtolower($person['email']));
        self::check($this->http($connection)->put("lists/{$audience}/members/{$hash}", ['email_address' => $person['email'], 'status_if_new' => 'subscribed',
            'merge_fields' => ['FNAME' => $person['first_name'], 'LNAME' => $person['last_name']]]));
        if ($person['tags'] !== []) {
            self::check($this->http($connection)->post("lists/{$audience}/members/{$hash}/tags", ['tags' => array_map(fn (string $t): array => ['name' => $t, 'status' => 'active'], $person['tags'])]));
        }
    }

    public function unsubscribe(ConnectionData $connection, string $audience, string $email): void
    {
        $hash = hash('md5', mb_strtolower($email));
        self::check($this->http($connection)->patch("lists/{$audience}/members/{$hash}", ['status' => 'unsubscribed']), [404]);
    }

    /** Mailchimp tells us by webhook; nothing to poll. */
    public function unsubscribedSince(ConnectionData $connection, string $audience, ?string $since): array
    {
        return [];
    }

    /** No signature: the address is the connection's own (unguessable) one, and the audience must be the chosen one. */
    public function verifyWebhook(string $raw, array $headers): bool
    {
        parse_str($raw, $p);

        return in_array($p['type'] ?? null, ['unsubscribe', 'cleaned', 'subscribe', 'profile', 'upemail', 'campaign'], true) && is_array($p['data'] ?? null);
    }

    public function webhook(ConnectionData $connection, array $payload): array
    {
        $data = (array) ($payload['data'] ?? []);
        if (! in_array($payload['type'] ?? null, ['unsubscribe', 'cleaned'], true) || ($data['list_id'] ?? null) !== ($connection->settings['audience'] ?? '')) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1];
        }
        $changed = app(EmailMarketing::class)->optOut((string) ($data['email'] ?? ''), 'Mailchimp');

        return ['created' => 0, 'updated' => $changed ? 1 : 0, 'skipped' => $changed ? 0 : 1];
    }

    public function disconnected(ConnectionData $connection): void
    {
        try {
            $id = $connection->settings['webhook_id'] ?? null;
            $audience = $connection->settings['audience'] ?? null;
            if (is_string($id) && is_string($audience)) {
                $this->http($connection)->delete("lists/{$audience}/webhooks/{$id}");
            }
        } catch (\Throwable) {
            // never throws
        }
    }

    /** The audience's webhook for unsubscribes, once per audience. */
    private function hook(ConnectionData $connection): void
    {
        $audience = (string) ($connection->settings['audience'] ?? '');
        if ($audience === '' || ($connection->settings['webhook_for'] ?? null) === $audience) {
            return;
        }
        $r = self::check($this->http($connection)->post("lists/{$audience}/webhooks", ['url' => $this->kit->webhookUrl($connection),
            'events' => ['unsubscribe' => true, 'cleaned' => true, 'subscribe' => false, 'profile' => false, 'upemail' => false, 'campaign' => false],
            'sources' => ['user' => true, 'admin' => true, 'api' => false]]));
        $this->kit->saveSettings($connection, ['webhook_id' => (string) $r->json('id'), 'webhook_for' => $audience]);
    }

    private function http(ConnectionData $connection): PendingRequest
    {
        $token = $this->kit->accessToken($connection);
        $base = $connection->settings['api_endpoint'] ?? null;
        if (! is_string($base) || preg_match('#^https://[a-z0-9]+\.api\.mailchimp\.com$#', $base) !== 1) {
            $meta = Http::withHeaders(['Authorization' => 'OAuth '.$token])->acceptJson()->timeout(15)->get(self::METADATA);
            $base = (string) $meta->json('api_endpoint');
            if (! $meta->successful() || preg_match('#^https://[a-z0-9]+\.api\.mailchimp\.com$#', $base) !== 1) {
                throw new NeedsReconnect('Mailchimp didn\'t accept the connection. Please connect it again.');
            }
            $this->kit->saveSettings($connection, ['api_endpoint' => $base, 'account' => (string) ($meta->json('accountname') ?? '')]);
        }

        return Http::withToken($token)->acceptJson()->asJson()->timeout(20)->retry(2, 500, throw: false)->baseUrl($base.'/3.0/');
    }

    /** @param  list<int>  $ok */
    private static function check(Response $r, array $ok = []): Response
    {
        if ($r->status() === 401) {
            throw new NeedsReconnect('Mailchimp needs you to connect it again.');
        }
        if (! $r->successful() && ! in_array($r->status(), $ok, true)) {
            throw new \RuntimeException('Mailchimp refused the request: '.mb_substr((string) ($r->json('detail') ?? $r->body()), 0, 200));
        }

        return $r;
    }
}
