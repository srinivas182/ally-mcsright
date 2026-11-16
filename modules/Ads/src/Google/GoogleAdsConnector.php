<?php

declare(strict_types=1);

namespace Modules\Ads\Google;

use Modules\Ads\Lifecycle;
use Modules\Ads\Models\Campaign;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;

/**
 * The Google Ads connection (specs/ads/05): one-click Google sign-in. Each run lists the accounts the person can run
 * ads from (for the ad builder) and refreshes the results of their running Google ads, with the stop-loss.
 */
final class GoogleAdsConnector implements Connector
{
    public function __construct(private readonly ConnectorKit $kit, private readonly GoogleAdsApi $api) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Google Ads';
    }

    public function sync(ConnectionData $connection): array
    {
        $token = $this->kit->accessToken($connection);
        $accounts = $this->api->accounts($token);
        $this->kit->saveSettings($connection, ['ad_accounts' => $accounts]);

        $updated = 0;
        foreach (Campaign::query()->where('connection_id', $connection->id)->where('channel', 'google')->where('billing', 'own')->whereIn('status', ['active', 'paused'])->get() as $c) {
            $results = $this->api->results($c, $token);
            if ($results !== null) {
                app(Lifecycle::class)->apply($c, $results, $token);
                $updated++;
            }
        }

        return ['created' => 0, 'updated' => $updated, 'skipped' => 0, 'cursor' => null,
            'note' => $accounts === [] ? 'No Google Ads accounts that can run ads were found for this Google login.' : ($updated > 0 ? 'Ad results updated.' : null)];
    }
}
