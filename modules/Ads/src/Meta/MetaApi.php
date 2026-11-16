<?php

declare(strict_types=1);

namespace Modules\Ads\Meta;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
        $form = $c->goal === 'form';
        $made = [];
        try {
            // The campaign name carries the ad's tag, so instant-form leads are credited to it (Crm FacebookLeads reads it).
            $made['campaign'] = self::id(self::post($http, $account.'/campaigns', ['name' => mb_substr('RightAlly: '.$c->listing_label, 0, 180).' · rightally-'.strtolower($c->ulid),
                'objective' => $form ? 'OUTCOME_LEADS' : 'OUTCOME_TRAFFIC', 'status' => 'PAUSED', 'special_ad_categories' => ['HOUSING'], 'special_ad_category_country' => ['US'],
                'is_adset_budget_sharing_enabled' => false]));
            $made['adset'] = self::id(self::post($http, $account.'/adsets', ['name' => $c->listing_label, 'campaign_id' => $made['campaign'],
                'daily_budget' => $c->daily_budget_cents, 'billing_event' => 'IMPRESSIONS', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                'start_time' => $c->starts_on->startOfDay()->toIso8601String(), 'end_time' => $c->ends_on->endOfDay()->toIso8601String(), 'status' => 'ACTIVE',
                'targeting' => self::targeting($c)] + ($form ? ['optimization_goal' => 'LEAD_GENERATION', 'destination_type' => 'ON_AD', 'promoted_object' => ['page_id' => $c->page_id]]
                    : ['optimization_goal' => 'LINK_CLICKS'])));
            if ($form) {
                $made['form'] = $this->leadForm($c, $token, (string) $c->privacy_url);
            }
            $made['creative'] = self::id(self::post($http, $account.'/adcreatives', ['name' => $c->listing_label] + $this->creative($c, $http, $account, $made['form'] ?? null)));
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

    /**
     * The ad itself: a promoted Page post as it is; a Reel as a video ad; a carousel of the Studio slides; or one photo.
     * Every call to action leads to the listing page, or to the instant form.
     *
     * @return array<string, mixed>
     */
    private function creative(Campaign $c, PendingRequest $http, string $account, ?string $form): array
    {
        if ($c->format === 'post' && $c->object_story_id !== null) {
            return ['object_story_id' => $c->object_story_id];
        }
        $cta = ['type' => $form !== null ? 'SIGN_UP' : 'LEARN_MORE', 'value' => $form !== null ? ['lead_gen_form_id' => $form, 'link' => $c->link] : ['link' => $c->link]];
        if ($c->format === 'video' && $c->video_url !== null) {
            $video = self::id(self::post($http, $account.'/advideos', ['file_url' => $c->video_url, 'name' => $c->listing_label]));
            $this->waitForVideo($http, $video);

            return ['object_story_spec' => ['page_id' => $c->page_id, 'video_data' => ['video_id' => $video, 'image_url' => $c->image_url, 'message' => $c->body,
                'title' => $c->headline, 'link_description' => self::disclosure($c), 'call_to_action' => $cta]]];
        }
        if ($c->format === 'carousel' && count($c->images ?? []) >= 2) {
            return ['object_story_spec' => ['page_id' => $c->page_id, 'link_data' => ['link' => $c->link, 'message' => $c->body, 'multi_share_optimized' => false,
                'multi_share_end_card' => false, 'call_to_action' => $cta,
                'child_attachments' => array_map(fn (string $img): array => ['link' => $c->link, 'picture' => $img, 'name' => $c->headline, 'description' => self::disclosure($c),
                    'call_to_action' => $cta], array_slice($c->images ?? [], 0, 10))]]];
        }

        return ['object_story_spec' => ['page_id' => $c->page_id, 'link_data' => ['link' => $c->link, 'message' => $c->body, 'name' => $c->headline, 'picture' => $c->image_url,
            'description' => self::disclosure($c), 'call_to_action' => $cta]]];
    }

    /**
     * An instant form on the Page: name, email and phone (prefilled by Meta), and what they'd like. Nothing that
     * describes the person (Housing ads), the brokerage's privacy policy, and a thank-you that leads to the listing.
     */
    private function leadForm(Campaign $c, string $token, string $privacyUrl): string
    {
        $page = Http::withToken($token)->acceptJson()->timeout(20)->get(self::GRAPH.$c->page_id, ['fields' => 'access_token']);
        self::check($page);
        $pageToken = (string) ($page->json('access_token') ?? '');
        if ($pageToken === '') {
            throw new RuntimeException('RightAlly can\'t make a form on this Page. Check that you manage it.');
        }
        $who = User::query()->find($c->user_id)->first_name ?? 'We';
        $r = Http::asJson()->acceptJson()->timeout(30)->post(self::GRAPH.$c->page_id.'/leadgen_forms', [
            'access_token' => $pageToken,
            'name' => mb_substr('RightAlly '.$c->listing_label.' '.strtolower($c->ulid), 0, 100),
            'locale' => 'en_US',
            'questions' => [['type' => 'FULL_NAME'], ['type' => 'EMAIL'], ['type' => 'PHONE'],
                ['type' => 'CUSTOM', 'key' => 'interest', 'label' => 'What would you like?', 'options' => [
                    ['key' => 'showing', 'value' => 'A private showing'], ['key' => 'details', 'value' => 'More details and photos'],
                    ['key' => 'value', 'value' => 'What my own home is worth']]]],
            'privacy_policy' => ['url' => $privacyUrl, 'link_text' => 'Privacy policy'],
            'follow_up_action_url' => $c->link,
            'thank_you_page' => ['title' => 'Thank you!', 'body' => mb_substr($who.' will be in touch soon. '.self::disclosure($c), 0, 300),
                'button_type' => 'VIEW_WEBSITE', 'button_text' => 'See the home', 'website_url' => $c->link],
        ]);
        self::check($r);

        return self::id($r);
    }

    /** Meta processes an uploaded video before an ad can use it: check every 5 seconds, for up to 3 minutes. */
    private function waitForVideo(PendingRequest $http, string $video): void
    {
        for ($i = 0; $i < 36; $i++) {
            $r = $http->get($video, ['fields' => 'status']);
            $state = (string) ($r->json('status.video_status') ?? '');
            if ($state === 'ready') {
                return;
            }
            if ($state === 'error') {
                throw new RuntimeException('Meta could not process the video.');
            }
            Sleep::sleep(5);
        }
        throw new RuntimeException('Meta is still processing the video. Try again in a few minutes.');
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
