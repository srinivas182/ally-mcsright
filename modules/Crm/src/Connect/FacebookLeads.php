<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Core\Connect\AppWebhooks;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Crm\Enums\Source;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Facebook and Instagram Lead Ads (specs/connect/10). The broker or agent signs in with Facebook and approves; every
 * Page they manage is subscribed to new leads. Each lead arrives within seconds through Meta's one webhook address,
 * is routed to the connection that owns the Page, read with the Page's own token, and goes through lead intake like
 * any other lead (routing, speed-to-lead, the AI responder), once only (by Meta's lead id).
 *
 * - A brokerage connection's leads are routed by the brokerage's rules; an agent's connection's leads are theirs.
 * - Texting consent is not assumed from a Facebook form: the lead is texted only after they opt in here.
 * - Disconnecting unsubscribes the Pages and stops the routing.
 */
final class FacebookLeads implements AppWebhooks
{
    public const GRAPH = 'https://graph.facebook.com/v21.0/';

    public function __construct(private readonly ConnectorKit $kit) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Facebook';
    }

    /** Swaps the sign-in for a long-lived one, then subscribes every Page to new leads (repeats are harmless). */
    public function sync(ConnectionData $connection): array
    {
        $keys = $this->kit->secrets('facebook_leads');
        $user = $this->kit->accessToken($connection);
        if (! isset($connection->credentials['long_lived'])) {
            $r = Http::timeout(20)->get(self::GRAPH.'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $keys['client_id'] ?? '',
                'client_secret' => $keys['client_secret'] ?? '', 'fb_exchange_token' => $user]);
            if (is_string($r->json('access_token'))) {
                $user = (string) $r->json('access_token');
                // Long-lived (about 60 days); the Page tokens taken from it do not expire.
                $this->kit->saveCredentials($connection, ['access_token' => $user, 'expires_at' => (string) (time() + (int) ($r->json('expires_in') ?? 5_184_000)), 'long_lived' => '1']);
            }
        }
        $r = Http::withToken($user)->timeout(20)->get(self::GRAPH.'me/accounts', ['fields' => 'id,name,access_token', 'limit' => 100]);
        if (in_array($r->status(), [400, 401, 403], true)) {
            throw new NeedsReconnect('Facebook no longer accepts the connection. Please connect it again.');
        }
        $r->throw();
        $pages = [];
        $subscribed = 0;
        foreach (array_values(array_filter((array) $r->json('data', []), 'is_array')) as $p) {
            if (! is_string($p['id'] ?? null) || ! is_string($p['access_token'] ?? null)) {
                continue;
            }
            $s = Http::asForm()->timeout(20)->post(self::GRAPH.$p['id'].'/subscribed_apps', ['subscribed_fields' => 'leadgen', 'access_token' => $p['access_token']]);
            if ($s->successful()) {
                $this->kit->routeTo($connection, $p['id']);
                $pages[$p['id']] = ['name' => (string) ($p['name'] ?? 'Page'), 'token' => $p['access_token']];
                $subscribed++;
            }
        }
        $this->kit->saveCredentials($connection, ['pages' => (string) json_encode(array_map(fn (array $p): string => $p['token'], $pages))]);
        $this->kit->saveSettings($connection, ['pages' => array_map(fn (array $p): string => $p['name'], $pages), 'interval_minutes' => 1440]);

        return ['created' => 0, 'updated' => $subscribed, 'skipped' => 0, 'cursor' => null,
            'note' => $subscribed > 0 ? 'Listening for new leads on '.$subscribed.' Page'.($subscribed === 1 ? '' : 's').'.' : 'No Facebook Pages to listen to. Make sure you manage at least one Page.'];
    }

    public function handshake(array $query): Response
    {
        $token = (string) ($this->kit->secrets('facebook_leads')['verify_token'] ?? '');
        if (($query['hub_mode'] ?? $query['hub.mode'] ?? null) !== 'subscribe' || $token === ''
            || ! hash_equals($token, (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? ''))) {
            return response('', 403);
        }

        return response((string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? ''), 200);
    }

    public function verifyWebhook(string $raw, array $headers): bool
    {
        $secret = (string) ($this->kit->secrets('facebook_leads')['client_secret'] ?? '');
        $given = (string) ($headers['x-hub-signature-256'] ?? '');

        return $secret !== '' && str_starts_with($given, 'sha256=') && hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $given);
    }

    public function route(array $payload): array
    {
        if (($payload['object'] ?? null) !== 'page') {
            return [];
        }
        $parts = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) (is_array($entry) ? ($entry['changes'] ?? []) : []) as $change) {
                $v = is_array($change) && ($change['field'] ?? null) === 'leadgen' && is_array($change['value'] ?? null) ? $change['value'] : null;
                if ($v !== null && is_scalar($v['page_id'] ?? null) && is_scalar($v['leadgen_id'] ?? null)) {
                    $parts[] = ['key' => (string) $v['page_id'], 'payload' => ['event' => 'leadgen', 'page_id' => (string) $v['page_id'], 'leadgen_id' => (string) $v['leadgen_id'],
                        'form_id' => is_scalar($v['form_id'] ?? null) ? (string) $v['form_id'] : null]];
                }
            }
        }

        return $parts;
    }

    public function webhook(ConnectionData $connection, array $payload): array
    {
        $tokens = json_decode((string) ($connection->credentials['pages'] ?? '{}'), true);
        $page = (string) ($payload['page_id'] ?? '');
        $token = is_array($tokens) && is_string($tokens[$page] ?? null) ? $tokens[$page] : null;
        if ($token === null || ! ctype_digit((string) ($payload['leadgen_id'] ?? ''))) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1];
        }
        $r = Http::timeout(20)->get(self::GRAPH.$payload['leadgen_id'], ['fields' => 'field_data,created_time,ad_name,campaign_name,form_id', 'access_token' => $token]);
        if (in_array($r->status(), [400, 401, 403], true)) {
            throw new NeedsReconnect('Facebook no longer lets RightAlly read leads for this Page. Please connect it again.');
        }
        $r->throw();
        $fields = [];
        foreach ((array) $r->json('field_data', []) as $f) {
            if (is_array($f) && is_string($f['name'] ?? null)) {
                $fields[strtolower($f['name'])] = trim((string) (((array) ($f['values'] ?? []))[0] ?? ''));
            }
        }
        $extra = array_diff_key($fields, array_flip(['full_name', 'first_name', 'last_name', 'email', 'phone_number', 'zip_code', 'post_code']));
        $taken = app(ConnectedLeads::class)->receive($connection, 'Facebook Lead Ads', Source::Facebook, [
            'external_id' => (string) $payload['leadgen_id'], 'name' => $fields['full_name'] ?? '', 'first_name' => $fields['first_name'] ?? '', 'last_name' => $fields['last_name'] ?? '',
            'email' => $fields['email'] ?? '', 'phone' => $fields['phone_number'] ?? '', 'zip' => $fields['zip_code'] ?? $fields['post_code'] ?? '',
            'message' => implode("\n", array_map(fn (string $k, string $v): string => Str::headline($k).': '.$v, array_keys($extra), $extra)),
            // A RightAlly ad carries its tag in the campaign name, so the lead is credited to that ad (specs/ads/03).
            'utm_source' => 'facebook', 'utm_medium' => 'lead_ad', 'utm_campaign' => is_string($r->json('campaign_name'))
                ? (preg_match('/rightally-[0-9a-hjkmnp-tv-z]{26}/', (string) $r->json('campaign_name'), $tag) === 1 ? $tag[0] : $r->json('campaign_name')) : null,
            'utm_content' => is_string($r->json('ad_name')) ? $r->json('ad_name') : null,
        ]);
        if (! $taken) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1]; // a test lead or one without a name, email or phone
        }

        return ['created' => 1, 'updated' => 0, 'skipped' => 0];
    }

    public function disconnected(ConnectionData $connection): void
    {
        try {
            $tokens = json_decode((string) ($connection->credentials['pages'] ?? '{}'), true);
            foreach (is_array($tokens) ? $tokens : [] as $page => $token) {
                Http::timeout(10)->delete(self::GRAPH.$page.'/subscribed_apps', ['access_token' => (string) $token]);
            }
        } catch (Throwable $e) {
            report($e); // never stop someone disconnecting
        }
        $this->kit->unroute($connection);
    }
}
