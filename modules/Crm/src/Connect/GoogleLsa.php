<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Crm\Enums\Source;

/**
 * Google Local Services Ads (specs/connect/11): every call, message and booking from the person's "Google Guaranteed"
 * ads becomes a lead, through the Google Ads API (one-click Google sign-in; RightAlly's developer token from HQ).
 *
 * Every 15 minutes: the Google Ads accounts the person can reach (refreshed daily), then each account's new Local
 * Services leads since the last check. An account without Local Services simply returns nothing.
 */
final class GoogleLsa implements Connector
{
    private const MAX_ACCOUNTS = 10;

    public function __construct(private readonly ConnectorKit $kit, private readonly ConnectedLeads $leads) {}

    public static function base(): string
    {
        return 'https://googleads.googleapis.com/'.config('connect.google_ads_version', 'v26').'/';
    }

    public function verify(array $credentials, array $settings): string
    {
        return 'Google Local Services Ads';
    }

    public function sync(ConnectionData $connection): array
    {
        $http = Http::withToken($this->kit->accessToken($connection))->acceptJson()->timeout(30)
            ->withHeaders(['developer-token' => (string) ($this->kit->secrets('google_lsa')['developer_token'] ?? '')])->baseUrl(self::base());
        $customers = $this->customers($connection, $http);
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $since = is_array($cursor) && is_string($cursor['since'] ?? null) ? $cursor['since'] : CarbonImmutable::now()->subDays(30)->format('Y-m-d H:i:s');
        $started = CarbonImmutable::now();
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($customers as $customer) {
            $r = $http->post('customers/'.$customer.'/googleAds:search', ['query' => 'SELECT local_services_lead.id, local_services_lead.lead_type, local_services_lead.category_id, '
                .'local_services_lead.contact_details, local_services_lead.lead_status, local_services_lead.creation_date_time FROM local_services_lead '
                ."WHERE local_services_lead.creation_date_time > '".$since."' ORDER BY local_services_lead.creation_date_time"]);
            if (in_array($r->status(), [401], true)) {
                throw new NeedsReconnect('Google no longer accepts the connection. Please connect it again.');
            }
            if (! $r->successful()) {
                continue; // not a Local Services account, or no access to it
            }
            foreach (array_values(array_filter((array) $r->json('results', []), 'is_array')) as $row) {
                $lead = is_array($row['localServicesLead'] ?? null) ? $row['localServicesLead'] : [];
                $contact = is_array($lead['contactDetails'] ?? null) ? $lead['contactDetails'] : [];
                if (! is_scalar($lead['id'] ?? null)) {
                    continue;
                }
                $type = strtolower(str_replace('_', ' ', (string) ($lead['leadType'] ?? 'lead')));
                $taken = $this->leads->receive($connection, 'Google Local Services Ads', Source::Google, [
                    'external_id' => 'lsa-'.$lead['id'], 'name' => (string) ($contact['consumerName'] ?? ''), 'email' => (string) ($contact['email'] ?? ''),
                    'phone' => (string) ($contact['phoneNumber'] ?? ''), 'message' => 'Google Local Services '.$type.'.', 'utm_source' => 'google', 'utm_medium' => 'local_services',
                ]);
                $counts[$taken ? 'created' : 'skipped']++;
            }
        }

        return $counts + ['cursor' => (string) json_encode(['since' => $started->subMinutes(5)->utc()->format('Y-m-d H:i:s')]),
            'note' => $customers === [] ? 'No Google Ads accounts found for this Google login.' : null];
    }

    /** @return list<string> account ids, refreshed daily */
    private function customers(ConnectionData $connection, PendingRequest $http): array
    {
        $saved = $connection->settings['customers'] ?? null;
        $at = $connection->settings['customers_at'] ?? null;
        if (is_array($saved) && is_string($at) && CarbonImmutable::parse($at)->gt(CarbonImmutable::now()->subDay())) {
            return array_values(array_map('strval', $saved));
        }
        $r = $http->get('customers:listAccessibleCustomers');
        if ($r->status() === 401) {
            throw new NeedsReconnect('Google no longer accepts the connection. Please connect it again.');
        }
        $r->throw();
        $ids = array_slice(array_values(array_filter(array_map(fn ($n): string => is_string($n) ? (string) preg_replace('/\D/', '', $n) : '', (array) $r->json('resourceNames', [])))), 0, self::MAX_ACCOUNTS);
        $this->kit->saveSettings($connection, ['customers' => $ids, 'customers_at' => CarbonImmutable::now()->toIso8601String(), 'interval_minutes' => 15]);

        return $ids;
    }
}
