<?php

declare(strict_types=1);

namespace Modules\Ads\Meta;

use Illuminate\Support\Facades\Http;
use Modules\Ads\Models\Campaign;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * The Meta Ads connection (specs/ads/01): after one-click sign-in, the sign-in is made long-lived; each run lists the
 * person's ad accounts and Pages (for the ad builder) and refreshes results of their running ads.
 */
final class MetaAdsConnector implements Connector
{
    public function __construct(private readonly ConnectorKit $kit, private readonly MetaApi $api) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Meta Ads';
    }

    public function sync(ConnectionData $connection): array
    {
        $token = $this->kit->accessToken($connection);
        if (! isset($connection->credentials['long_lived'])) {
            $keys = $this->kit->secrets('meta_ads');
            $r = Http::timeout(20)->get(MetaApi::GRAPH.'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $keys['client_id'] ?? '',
                'client_secret' => $keys['client_secret'] ?? '', 'fb_exchange_token' => $token]);
            if (is_string($r->json('access_token'))) {
                $token = (string) $r->json('access_token');
                $this->kit->saveCredentials($connection, ['access_token' => $token, 'expires_at' => (string) (time() + (int) ($r->json('expires_in') ?? 5_184_000)), 'long_lived' => '1']);
            }
        }
        $accounts = Http::withToken($token)->timeout(20)->get(MetaApi::GRAPH.'me/adaccounts', ['fields' => 'account_id,name,account_status,currency', 'limit' => 100]);
        if (in_array($accounts->status(), [400, 401, 403], true) && (int) $accounts->json('error.code') === 190) {
            throw new NeedsReconnect('Facebook asked you to sign in again. Please connect Meta Ads again.');
        }
        $accounts->throw();
        $pages = Http::withToken($token)->timeout(20)->get(MetaApi::GRAPH.'me/accounts', ['fields' => 'id,name', 'limit' => 100]);
        $list = fn (mixed $rows, string $id): array => array_values(array_map(fn (array $a): array => ['id' => (string) $a[$id], 'name' => (string) ($a['name'] ?? $a[$id])],
            array_filter((array) $rows, fn ($a): bool => is_array($a) && is_scalar($a[$id] ?? null) && (($a['account_status'] ?? 1) === 1))));
        $this->kit->saveSettings($connection, ['ad_accounts' => $list($accounts->json('data', []), 'account_id'), 'pages' => $list($pages->json('data', []), 'id')]);

        $updated = 0;
        foreach (Campaign::query()->where('connection_id', $connection->id)->whereIn('status', ['active', 'paused'])->get() as $c) {
            $results = $this->api->results($c, $token);
            if ($results !== null) {
                $c->forceFill($results + ['results_at' => now(), 'status' => $c->status === 'active' && $c->ends_on->endOfDay()->isPast() ? 'ended' : $c->status])->save();
                $updated++;
            }
        }

        return ['created' => 0, 'updated' => $updated, 'skipped' => 0, 'cursor' => null, 'note' => $updated > 0 ? 'Ad results updated.' : null];
    }
}
