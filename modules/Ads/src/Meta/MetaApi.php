<?php

declare(strict_types=1);

namespace Modules\Ads\Meta;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Ads\Models\Campaign;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;

/**
 * Publishing a listing ad with the Meta Marketing API, from the person's own ad account (specs/ads/01).
 *
 * Housing rules (US Fair Housing; Meta's Housing special ad category): the campaign is marked HOUSING, and the
 * audience is only a place: a radius of at least 15 miles around the home, every age from 18, every gender, no
 * detailed targeting. RightAlly never narrows an audience by anything else.
 *
 * Order matters: the campaign is made paused, then the ad set, creative and ad; it is switched on last, so a step
 * that fails never leaves an ad spending. On failure, what was made is deleted.
 */
final class MetaApi
{
    public const GRAPH = 'https://graph.facebook.com/v21.0/';

    public const MIN_RADIUS_MILES = 15;

    public function publish(Campaign $c, string $token): void
    {
        $http = self::http($token);
        $account = 'act_'.ltrim($c->ad_account_id, 'act_');
        $made = [];
        try {
            $made['campaign'] = self::id(self::post($http, $account.'/campaigns', ['name' => 'RightAlly: '.$c->listing_label, 'objective' => 'OUTCOME_TRAFFIC',
                'status' => 'PAUSED', 'special_ad_categories' => ['HOUSING'], 'special_ad_category_country' => ['US'], 'is_adset_budget_sharing_enabled' => false]));
            $made['adset'] = self::id(self::post($http, $account.'/adsets', ['name' => $c->listing_label, 'campaign_id' => $made['campaign'],
                'daily_budget' => $c->daily_budget_cents, 'billing_event' => 'IMPRESSIONS', 'optimization_goal' => 'LINK_CLICKS', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                'start_time' => $c->starts_on->startOfDay()->toIso8601String(), 'end_time' => $c->ends_on->endOfDay()->toIso8601String(), 'status' => 'ACTIVE',
                'targeting' => self::targeting($c)]));
            $made['creative'] = self::id(self::post($http, $account.'/adcreatives', ['name' => $c->listing_label, 'object_story_spec' => ['page_id' => $c->page_id,
                'link_data' => ['link' => $c->link, 'message' => $c->body, 'name' => $c->headline, 'picture' => $c->image_url, 'description' => self::disclosure($c),
                    'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['link' => $c->link]]]]]));
            $made['ad'] = self::id(self::post($http, $account.'/ads', ['name' => $c->listing_label, 'adset_id' => $made['adset'],
                'creative' => ['creative_id' => $made['creative']], 'status' => 'ACTIVE']));
            self::post($http, $made['campaign'], ['status' => 'ACTIVE']);
        } catch (\Throwable $e) {
            if (isset($made['campaign'])) {
                $http->delete($made['campaign']); // nothing half-made is left behind
            }
            throw $e;
        }
        $c->forceFill(['meta_ids' => $made])->save();
    }

    /** Who the listing is with, on every ad (state advertising rules name the brokerage). */
    public static function disclosure(Campaign $c): string
    {
        $agent = User::query()->find($c->user_id)?->name;

        return mb_substr('Listed by '.($agent !== null ? $agent.', ' : '').app(TenantContext::class)->current()->name, 0, 120);
    }

    /** @return array<string, mixed> place only, as Housing ads require */
    public static function targeting(Campaign $c): array
    {
        return ['geo_locations' => ['custom_locations' => [['latitude' => (float) $c->latitude, 'longitude' => (float) $c->longitude,
            'radius' => max(self::MIN_RADIUS_MILES, $c->radius_miles), 'distance_unit' => 'mile']]], 'age_min' => 18, 'age_max' => 65];
    }

    public function setStatus(Campaign $c, string $token, bool $active): void
    {
        $id = $c->meta_ids['campaign'] ?? throw new RuntimeException('This ad was never published.');
        self::post(self::http($token), $id, ['status' => $active ? 'ACTIVE' : 'PAUSED']);
    }

    /** @return array{spend_cents: int, impressions: int, clicks: int, leads: int}|null */
    public function results(Campaign $c, string $token): ?array
    {
        $id = $c->meta_ids['campaign'] ?? null;
        if ($id === null) {
            return null;
        }
        $r = self::http($token)->get($id.'/insights', ['fields' => 'spend,impressions,clicks,actions', 'date_preset' => 'maximum']);
        self::check($r);
        $row = (array) ($r->json('data.0') ?? []);
        $leads = 0;
        foreach ((array) ($row['actions'] ?? []) as $a) {
            if (is_array($a) && in_array($a['action_type'] ?? null, ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead'], true)) {
                $leads += (int) ($a['value'] ?? 0);
            }
        }

        return ['spend_cents' => (int) round((float) ($row['spend'] ?? 0) * 100), 'impressions' => (int) ($row['impressions'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0), 'leads' => $leads];
    }

    private static function http(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->asJson()->timeout(30)->baseUrl(self::GRAPH);
    }

    /** @param  array<string, mixed>  $body */
    private static function post(PendingRequest $http, string $path, array $body): Response
    {
        $r = $http->post($path, $body);
        self::check($r);

        return $r;
    }

    private static function check(Response $r): void
    {
        if ($r->successful()) {
            return;
        }
        if ((int) $r->json('error.code') === 190) {
            throw new NeedsReconnect('Facebook asked you to sign in again. Reconnect Meta Ads under Apps.');
        }
        $message = $r->json('error.error_user_msg') ?? $r->json('error.message') ?? 'Meta did not accept the ad.';
        throw new RuntimeException(mb_substr((string) $message, 0, 300));
    }

    private static function id(Response $r): string
    {
        $id = $r->json('id');

        return is_scalar($id) ? (string) $id : throw new RuntimeException('Meta did not return an id.');
    }
}
