<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Mail\PlatformEmail;
use Modules\Core\Watchtower\ErrorTracking;
use Modules\Core\Watchtower\Jobs\SendToErrorTracking;
use Modules\Core\Watchtower\Signal;
use Throwable;

/**
 * "Check connection" (FL1, specs/core/16): one harmless read-only call per provider (an account, balance or list
 * call) that proves the keys work, in test or live. Nothing is sent to people and nothing is charged. Providers
 * without a safe read-only call get a format check, and the full check happens the first time the feature uses them.
 * Nothing about the person checking (no email or name) is sent.
 */
final class ConnectionCheck
{
    private const TIMEOUT = 10;

    /**
     * @param  array<string, string>  $keys
     * @return array{ok: bool, message: string}
     */
    public function run(string $provider, string $mode, array $keys): array
    {
        foreach ($this->requiredFields($provider) as $field) {
            if (trim((string) ($keys[$field] ?? '')) === '') {
                return ['ok' => false, 'message' => 'Fill in every box for '.($mode === 'live' ? 'Live' : 'Test').' first.'];
            }
        }
        try {
            return $this->check($provider, $mode, $keys);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Could not reach the provider. Check the server address and try again in a minute.'];
        }
    }

    /**
     * @param  array<string, string>  $k
     * @return array{ok: bool, message: string}
     */
    private function check(string $provider, string $mode, array $k): array
    {
        $test = $mode !== 'live';

        return match ($provider) {
            'retell' => $this->status($this->http()->withToken($k['api_key'])->get('https://api.retellai.com/list-agents')),
            'vapi' => $this->status($this->http()->withToken($k['api_key'])->get('https://api.vapi.ai/assistant', ['limit' => 1])),
            'bland' => $this->status($this->http()->withHeaders(['authorization' => $k['api_key']])->get('https://api.bland.ai/v1/calls', ['limit' => 1])),
            'deepgram' => $this->status($this->http()->withHeaders(['Authorization' => 'Token '.$k['api_key']])->get('https://api.deepgram.com/v1/projects')),
            'assemblyai' => $this->status($this->http()->withHeaders(['authorization' => $k['api_key']])->get('https://api.assemblyai.com/v2/transcript', ['limit' => 1])),
            'elevenlabs' => $this->status($this->http()->withHeaders(['xi-api-key' => $k['api_key']])->get('https://api.elevenlabs.io/v1/user')),
            'plaid' => $this->status($this->http()->post(($test ? 'https://sandbox.plaid.com' : 'https://production.plaid.com').'/institutions/get',
                ['client_id' => $k['client_id'], 'secret' => $k['secret'], 'count' => 1, 'offset' => 0, 'country_codes' => ['US']])),
            'mx' => $this->status($this->http()->withBasicAuth($k['client_id'], $k['api_key'])->accept('application/vnd.mx.api.v1+json')
                ->get(($test ? 'https://int-api.mx.com' : 'https://api.mx.com').'/institutions', ['records_per_page' => 1])),
            'typesense' => $this->status($this->http()->withHeaders(['X-TYPESENSE-API-KEY' => $k['api_key']])->get($this->host($k['host']).'/collections')),
            'meilisearch' => $this->status($this->http()->withToken($k['api_key'])->get($this->host($k['host']).'/keys', ['limit' => 1])),
            'opensearch' => $this->status($this->http()->withBasicAuth($k['username'], $k['password'])->get($this->host($k['host']).'/')),
            'matterport' => $this->status($this->http()->withBasicAuth($k['token_id'], $k['token_secret'])->post('https://api.matterport.com/api/models/graph', ['query' => '{ __typename }'])),
            'zerobounce' => $this->zeroBounce($k['api_key']),
            'neverbounce' => $this->json($this->http()->get('https://api.neverbounce.com/v4/account/info', ['key' => $k['api_key']]), fn (array $j): bool => ($j['status'] ?? '') === 'success'),
            'kickbox' => $this->json($this->http()->get('https://api.kickbox.com/v2/balance', ['apikey' => $k['api_key']]), fn (array $j): bool => ($j['success'] ?? false) === true),
            'twilio_lookup' => $this->status($this->http()->withBasicAuth($k['sid'], $k['token'])->get('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($k['sid']).'.json')),
            'telnyx' => $this->status($this->http()->withToken($k['api_key'])->get('https://api.telnyx.com/v2/balance')),
            'numverify' => $this->json($this->http()->get('https://apilayer.net/api/validate', ['access_key' => $k['api_key'], 'number' => '14158586273']),
                fn (array $j): bool => ! array_key_exists('success', $j) || $j['success'] !== false),
            'stripe' => $this->stripe($k['secret_key'], $test),
            // EM1: sign in to the provider's mail server (nothing is sent).
            'brevo', 'postmark_send', 'ses' => ($why = PlatformEmail::check($provider, $k)) === null
                ? ['ok' => true, 'message' => 'Signed in to the mail server. Switch to Test and send yourself an invitation to see it arrive.']
                : ['ok' => false, 'message' => $why],
            // OB1: one test event to the project; Bugsnag and Honeybadger keys are checked for shape (the default line).
            'sentry' => ErrorTracking::sentryDsn($k['dsn']) !== null && SendToErrorTracking::sentry($k['dsn'], ErrorTracking::payload('sentry',
                new Signal('check', 'connection-test', 'RightAlly connection test', message: 'RightAlly connection test: you can resolve this.'), $test ? 'test' : 'production'))
                ? ['ok' => true, 'message' => 'A test event reached Sentry. You can resolve it there.']
                : ['ok' => false, 'message' => 'Paste the whole DSN from Sentry (https://…@….ingest.sentry.io/…), and check the project accepts events.'],
            'paddle' => $this->status($this->http()->withToken($k['api_key'])->get(($test ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com').'/event-types')),
            'chargebee' => $this->status($this->http()->withBasicAuth($k['api_key'], '')->get('https://'.rawurlencode($k['site']).'.chargebee.com/api/v2/item_prices', ['limit' => 1])),
            'turnstile' => $this->captcha('https://challenges.cloudflare.com/turnstile/v0/siteverify', $k['secret_key']),
            'hcaptcha' => $this->captcha('https://api.hcaptcha.com/siteverify', $k['secret_key']),
            'recaptcha' => $this->captcha('https://www.google.com/recaptcha/api/siteverify', $k['secret_key']),
            'postmark' => $this->status($this->http()->withHeaders(['X-Postmark-Server-Token' => $k['server_token']])->get('https://api.postmarkapp.com/server')),
            'sendgrid' => $this->status($this->http()->withToken($k['api_key'])->get('https://api.sendgrid.com/v3/scopes')),
            'mailgun' => $this->status($this->http()->withBasicAuth('api', $k['api_key'])->get('https://api.mailgun.net/v3/domains/'.rawurlencode($k['domain']))),
            'shotstack' => $this->status($this->http()->withHeaders(['x-api-key' => $k['api_key']])->get('https://api.shotstack.io/serve/'.($test ? 'stage' : 'v1').'/assets/render/none'), [404]),
            'creatomate' => $this->status($this->http()->withToken($k['api_key'])->get('https://api.creatomate.com/v1/templates')),
            'own', 'state_data', 'manual' => ['ok' => true, 'message' => 'Built in. Nothing to connect.'],
            'teller' => $this->format(str_contains($k['certificate'], 'BEGIN CERTIFICATE') && str_contains($k['certificate'], 'PRIVATE KEY'),
                'Paste the certificate and its private key together, as Teller gave them.'),
            // C03: a one-node Overpass question; a Geoapify place look-up; Azure's endpoint must be https.
            'osm' => $this->status($this->http()->asForm()->post($this->host($k['host']), ['data' => '[out:json][timeout:5];node(1);out ids;'])),
            'geoapify' => $this->status($this->http()->get('https://api.geoapify.com/v1/geocode/search', ['text' => 'Austin, TX', 'limit' => 1, 'apiKey' => $k['api_key']])),
            'azure_vision' => $this->format(str_starts_with(trim($k['endpoint']), 'https://') && mb_strlen(trim($k['api_key'])) >= 16, 'Use the https endpoint and the whole key from the Azure portal.'),
            default => $this->format(mb_strlen(trim((string) ($k['api_key'] ?? ''))) >= 16, 'That key looks too short. Copy the whole key.'),
        };
    }

    /** @return list<string> */
    private function requiredFields(string $provider): array
    {
        foreach (Catalog::all() as $service) {
            if (isset($service['providers'][$provider])) {
                return array_keys($service['providers'][$provider]['fields']);
            }
        }

        return [];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT)->acceptJson();
    }

    private function host(string $host): string
    {
        $host = rtrim(trim($host), '/');
        if (! str_starts_with($host, 'https://')) {
            throw new \InvalidArgumentException('https only');
        }

        return $host;
    }

    /**
     * @param  list<int>  $alsoFine  status codes that still prove the key was accepted
     * @return array{ok: bool, message: string}
     */
    private function status(Response $r, array $alsoFine = []): array
    {
        if ($r->successful() || in_array($r->status(), $alsoFine, true)) {
            return ['ok' => true, 'message' => 'Connected. The keys work.'];
        }

        return ['ok' => false, 'message' => in_array($r->status(), [401, 403], true)
            ? 'The provider did not accept these keys. Copy them again from your account.'
            : 'The provider answered with an error ('.$r->status().'). Check the keys and try again.'];
    }

    /**
     * @param  callable(array<string, mixed>): bool  $ok
     * @return array{ok: bool, message: string}
     */
    private function json(Response $r, callable $ok): array
    {
        $j = $r->json();

        return $r->successful() && is_array($j) && $ok($j) ? ['ok' => true, 'message' => 'Connected. The keys work.']
            : ['ok' => false, 'message' => 'The provider did not accept these keys. Copy them again from your account.'];
    }

    /** @return array{ok: bool, message: string} */
    private function zeroBounce(string $key): array
    {
        $r = $this->http()->get('https://api.zerobounce.net/v2/getcredits', ['api_key' => $key]);
        $credits = (int) ($r->json('Credits') ?? -1);

        return $r->successful() && $credits >= 0 ? ['ok' => true, 'message' => 'Connected. '.number_format($credits).' checks left on the account.']
            : ['ok' => false, 'message' => 'ZeroBounce did not accept this key. Copy it again from your account.'];
    }

    /** @return array{ok: bool, message: string} */
    private function stripe(string $secret, bool $test): array
    {
        $prefix = $test ? 'sk_test_' : 'sk_live_';
        if (! str_starts_with($secret, $prefix) && ! str_starts_with($secret, $test ? 'rk_test_' : 'rk_live_')) {
            return ['ok' => false, 'message' => ($test ? 'Test' : 'Live').' needs a key starting '.$prefix.'.'];
        }

        return $this->status($this->http()->withToken($secret)->get('https://api.stripe.com/v1/balance'));
    }

    /**
     * Asks the captcha service to check a made-up answer: a good secret is told "bad answer", a bad secret "bad secret".
     *
     * @return array{ok: bool, message: string}
     */
    private function captcha(string $url, string $secret): array
    {
        $r = $this->http()->asForm()->post($url, ['secret' => $secret, 'response' => 'rightally-connection-check']);
        $codes = (array) ($r->json('error-codes') ?? []);
        $badSecret = array_intersect($codes, ['invalid-input-secret', 'missing-input-secret', 'sitekey-secret-mismatch']) !== [];

        return $r->successful() && ! $badSecret ? ['ok' => true, 'message' => 'Connected. The secret key works.']
            : ['ok' => false, 'message' => 'The secret key was not accepted. Copy it again.'];
    }

    /** @return array{ok: bool, message: string} */
    private function format(bool $ok, string $problem): array
    {
        return $ok ? ['ok' => true, 'message' => 'Keys saved. A full check runs the first time this is used.'] : ['ok' => false, 'message' => $problem];
    }
}
