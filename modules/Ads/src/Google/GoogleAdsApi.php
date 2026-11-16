<?php

declare(strict_types=1);

namespace Modules\Ads\Google;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Ads\AdNetwork;
use Modules\Ads\Meta\MetaApi;
use Modules\Ads\Models\Campaign;
use Modules\Ads\Studio\BrandKits;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;

/**
 * Google search and YouTube listing ads through the Google Ads API (specs/ads/05). The whole ad is made in one
 * atomic mutate request. Housing ads policy: no audience or demographic criteria, ever; search ads by radius around
 * the home, YouTube ads by the home's city. The developer token is RightAlly's (HQ, app `google_ads`).
 */
final class GoogleAdsApi implements AdNetwork
{
    public const MIN_RADIUS_MILES = MetaApi::MIN_RADIUS_MILES;

    private const ENGLISH = 'languageConstants/1000';

    public static function base(): string
    {
        return 'https://googleads.googleapis.com/'.config('connect.google_ads_version', 'v26').'/';
    }

    public function publish(Campaign $c, string $token): void
    {
        $customer = self::digits($c->ad_account_id);
        $login = self::digits((string) ($c->google_ids['login'] ?? ''));
        $http = self::http($token, $login);
        $video = $c->format === 'video';
        $res = fn (string $type, int $id): string => 'customers/'.$customer.'/'.$type.'/'.$id;
        $ops = [
            ['campaignBudgetOperation' => ['create' => ['resourceName' => $res('campaignBudgets', -1), 'name' => 'RightAlly '.strtolower($c->ulid),
                'amountMicros' => (string) ($c->daily_budget_cents * 10_000), 'deliveryMethod' => 'STANDARD', 'explicitlyShared' => false]]],
            ['campaignOperation' => ['create' => [
                'resourceName' => $res('campaigns', -2),
                // The name carries the ad's tag, like Meta ads, so every report ties back to it.
                'name' => mb_substr('RightAlly: '.$c->listing_label, 0, 200).' · rightally-'.strtolower($c->ulid),
                'advertisingChannelType' => $video ? 'DEMAND_GEN' : 'SEARCH',
                'status' => 'ENABLED',
                'campaignBudget' => $res('campaignBudgets', -1),
                'targetSpend' => (object) [], // Maximize clicks: the link is the listing page, where leads arrive
                'startDateTime' => $c->starts_on->format('Y-m-d').' 00:00:00',
                'endDateTime' => $c->ends_on->format('Y-m-d').' 23:59:59',
                'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            ] + ($video ? [] : ['networkSettings' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => false, 'targetContentNetwork' => false, 'targetPartnerSearchNetwork' => false]])]],
        ];
        if ($video) {
            $geo = $this->city($http, $c);
            $ops[] = ['adGroupOperation' => ['create' => ['resourceName' => $res('adGroups', -3), 'campaign' => $res('campaigns', -2), 'name' => mb_substr($c->listing_label, 0, 200),
                'status' => 'ENABLED', 'demandGenAdGroupSettings' => ['channelControls' => ['selectedChannels' => ['youtubeInStream' => true, 'youtubeInFeed' => true,
                    'youtubeShorts' => true, 'discover' => false, 'gmail' => false, 'display' => false, 'maps' => false]]]]]];
            $ops[] = ['adGroupCriterionOperation' => ['create' => ['adGroup' => $res('adGroups', -3), 'location' => ['geoTargetConstant' => $geo]]]];
            $ops[] = ['adGroupCriterionOperation' => ['create' => ['adGroup' => $res('adGroups', -3), 'language' => ['languageConstant' => self::ENGLISH]]]];
            $ops[] = ['assetOperation' => ['create' => ['resourceName' => $res('assets', -4), 'name' => 'RightAlly video '.strtolower($c->ulid),
                'youtubeVideoAsset' => ['youtubeVideoId' => (string) $c->youtube_video_id]]]];
            $ops[] = ['assetOperation' => ['create' => ['resourceName' => $res('assets', -5), 'name' => 'RightAlly logo '.strtolower($c->ulid),
                'imageAsset' => ['data' => base64_encode($this->logo($c))]]]];
            $copy = (array) ($c->google_ids['copy'] ?? []);
            $ops[] = ['adGroupAdOperation' => ['create' => ['adGroup' => $res('adGroups', -3), 'status' => 'ENABLED', 'ad' => ['finalUrls' => [$c->link],
                'demandGenVideoResponsiveAd' => ['headlines' => self::texts($copy['video_headlines'] ?? []), 'longHeadlines' => self::texts($copy['long_headlines'] ?? []),
                    'descriptions' => self::texts(array_slice((array) ($copy['descriptions'] ?? []), 0, 5)), 'businessName' => ['text' => (string) ($copy['business'] ?? self::business())],
                    'videos' => [['asset' => $res('assets', -4)]], 'logoImages' => [['asset' => $res('assets', -5)]]]]]]];
        } else {
            $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $res('campaigns', -2), 'proximity' => [
                'geoPoint' => ['latitudeInMicroDegrees' => (int) round((float) $c->latitude * 1_000_000), 'longitudeInMicroDegrees' => (int) round((float) $c->longitude * 1_000_000)],
                'radius' => (float) max(self::MIN_RADIUS_MILES, $c->radius_miles), 'radiusUnits' => 'MILES']]]];
            $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $res('campaigns', -2), 'language' => ['languageConstant' => self::ENGLISH]]]];
            $ops[] = ['adGroupOperation' => ['create' => ['resourceName' => $res('adGroups', -3), 'campaign' => $res('campaigns', -2), 'name' => mb_substr($c->listing_label, 0, 200),
                'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD']]];
            foreach ((array) $c->keywords as $kw) {
                $ops[] = ['adGroupCriterionOperation' => ['create' => ['adGroup' => $res('adGroups', -3), 'status' => 'ENABLED',
                    'keyword' => ['text' => (string) $kw, 'matchType' => 'PHRASE']]]];
            }
            $copy = (array) ($c->google_ids['copy'] ?? []);
            $ops[] = ['adGroupAdOperation' => ['create' => ['adGroup' => $res('adGroups', -3), 'status' => 'ENABLED', 'ad' => ['finalUrls' => [$c->link],
                'responsiveSearchAd' => ['headlines' => self::texts($copy['headlines'] ?? []), 'descriptions' => self::texts($copy['descriptions'] ?? [])]]]]];
        }
        $r = $http->post('customers/'.$customer.'/googleAds:mutate', ['mutateOperations' => $ops]);
        self::check($r);
        $made = ['customer' => $customer, 'login' => $login] + (array) $c->google_ids;
        foreach ((array) $r->json('mutateOperationResponses', []) as $one) {
            foreach (['campaignBudgetResult' => 'budget', 'campaignResult' => 'campaign', 'adGroupResult' => 'ad_group', 'adGroupAdResult' => 'ad'] as $key => $name) {
                if (is_array($one) && is_string($one[$key]['resourceName'] ?? null)) {
                    $made[$name] = $one[$key]['resourceName'];
                }
            }
        }
        if (! isset($made['campaign'])) {
            throw new RuntimeException('Google did not return the campaign.');
        }
        $c->forceFill(['google_ids' => $made])->save();
    }

    public function setStatus(Campaign $c, string $token, bool $active): void
    {
        $campaign = (string) ($c->google_ids['campaign'] ?? throw new RuntimeException('This ad was never published.'));
        $r = self::http($token, (string) ($c->google_ids['login'] ?? ''))->post('customers/'.self::digits($c->ad_account_id).'/googleAds:mutate', ['mutateOperations' => [
            ['campaignOperation' => ['update' => ['resourceName' => $campaign, 'status' => $active ? 'ENABLED' : 'PAUSED'], 'updateMask' => 'status']]]]);
        self::check($r);
    }

    public function results(Campaign $c, string $token): ?array
    {
        $campaign = $c->google_ids['campaign'] ?? null;
        if (! is_string($campaign) || preg_match('#^customers/\d+/campaigns/\d+$#', $campaign) !== 1) {
            return null;
        }
        $r = self::http($token, (string) ($c->google_ids['login'] ?? ''))->post('customers/'.self::digits($c->ad_account_id).'/googleAds:search', ['query' => 'SELECT metrics.cost_micros, '
            ."metrics.impressions, metrics.clicks, metrics.conversions FROM campaign WHERE campaign.resource_name = '".$campaign."'"]);
        self::check($r);
        $m = (array) ($r->json('results.0.metrics') ?? []);

        return ['spend_cents' => (int) round(((int) ($m['costMicros'] ?? 0)) / 10_000), 'impressions' => (int) ($m['impressions'] ?? 0),
            'clicks' => (int) ($m['clicks'] ?? 0), 'leads' => (int) floor((float) ($m['conversions'] ?? 0))];
    }

    /**
     * The accounts a Google login can run ads from: each one it reaches directly, and the client accounts of a
     * manager account (reached through it). Manager accounts themselves can't run ads.
     *
     * @return list<array{id: string, name: string, login: string}>
     */
    public function accounts(string $token, int $max = 10): array
    {
        $r = self::http($token, '')->get('customers:listAccessibleCustomers');
        self::check($r);
        $out = [];
        foreach ((array) $r->json('resourceNames', []) as $name) {
            $id = self::digits(is_string($name) ? $name : '');
            $q = self::http($token, $id)->post('customers/'.$id.'/googleAds:search', ['query' => 'SELECT customer_client.id, customer_client.descriptive_name, customer_client.manager, '
                .'customer_client.status, customer_client.level FROM customer_client WHERE customer_client.level <= 1']);
            if ($id === '' || ! $q->successful()) {
                continue; // cancelled, or no access
            }
            foreach ((array) $q->json('results', []) as $row) {
                $cc = is_array($row) ? (array) ($row['customerClient'] ?? []) : [];
                $cid = self::digits((string) ($cc['id'] ?? ''));
                if ($cid === '' || ($cc['manager'] ?? false) === true || ($cc['status'] ?? 'ENABLED') !== 'ENABLED' || isset($out[$cid])) {
                    continue;
                }
                $out[$cid] = ['id' => $cid, 'name' => (string) ($cc['descriptiveName'] ?? $cid), 'login' => $cid === $id ? '' : $id];
                if (count($out) >= $max) {
                    break 2;
                }
            }
        }

        return array_values($out);
    }

    /** YouTube ads reach the home's city (Demand Gen targets by place): Google's id for it. */
    private function city(PendingRequest $http, Campaign $c): string
    {
        $city = trim((string) ($c->google_ids['city'] ?? ''));
        $r = $http->post('geoTargetConstants:suggest', ['locale' => 'en', 'countryCode' => 'US', 'locationNames' => ['names' => [$city]]]);
        self::check($r);
        foreach ((array) $r->json('geoTargetConstantSuggestions', []) as $s) {
            $g = is_array($s) ? (array) ($s['geoTargetConstant'] ?? []) : [];
            if (in_array($g['targetType'] ?? null, ['City', 'Municipality'], true) && is_string($g['resourceName'] ?? null)) {
                return $g['resourceName'];
            }
        }
        throw new RuntimeException('Google doesn\'t know the city "'.$city.'" for a YouTube ad. Try a search ad instead.');
    }

    /** A square logo for the YouTube ad: the brokerage logo, or the agent's headshot, centred on white. */
    private function logo(Campaign $c): string
    {
        $user = User::query()->find($c->user_id) ?? throw new RuntimeException('The agent is gone.');

        return app(BrandKits::class)->squareLogo($user) ?? throw new RuntimeException('Add the brokerage logo or your headshot in Brand kit first: YouTube ads show one.');
    }

    private static function business(): string
    {
        return mb_substr((string) app(TenantContext::class)->current()->name, 0, 25);
    }

    /**
     * @param  array<mixed>  $lines
     * @return list<array{text: string}>
     */
    private static function texts(array $lines): array
    {
        return array_values(array_map(fn (mixed $t): array => ['text' => (string) $t], $lines));
    }

    public static function digits(string $id): string
    {
        return (string) preg_replace('/\D/', '', $id);
    }

    private static function http(string $token, string $login): PendingRequest
    {
        $headers = ['developer-token' => (string) (app(ConnectorKit::class)->secrets('google_ads')['developer_token'] ?? '')];
        if (self::digits($login) !== '') {
            $headers['login-customer-id'] = self::digits($login);
        }

        return Http::withToken($token)->acceptJson()->asJson()->timeout(60)->withHeaders($headers)->baseUrl(self::base());
    }

    private static function check(Response $r): void
    {
        if ($r->successful()) {
            return;
        }
        if ($r->status() === 401) {
            throw new NeedsReconnect('Google asked you to sign in again. Reconnect Google Ads under Apps.');
        }
        $detail = $r->json('error.details.0.errors.0.message');
        $message = is_string($detail) && $detail !== '' ? $detail : ($r->json('error.message') ?? 'Google did not accept the ad.');
        throw new RuntimeException(mb_substr('Google: '.(string) $message, 0, 300));
    }
}
