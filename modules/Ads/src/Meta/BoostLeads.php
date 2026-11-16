<?php

declare(strict_types=1);

namespace Modules\Ads\Meta;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Ads\Boost;
use Modules\Ads\Jobs\ReceiveBoostLead;
use Modules\Ads\Models\Campaign;
use Modules\Core\Connect\UnroutedWebhook;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Enums\Source;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\LeadSource;
use RuntimeException;

/**
 * Instant-form leads from RightAlly Boost ads (specs/ads/04). The form is on RightAlly's Page, so Facebook sends the
 * lead to RightAlly's webhook; the form id says which brokerage and ad it belongs to, and the lead is read with
 * RightAlly's token and handed only to that brokerage's CRM, assigned to the agent who paid for the ad (with the ad's
 * tag, so it counts in its results). RightAlly keeps no copy.
 */
#[CrossTenantAccess('Hands a Boost lead-form lead only to the brokerage whose ad it came from')]
final class BoostLeads implements UnroutedWebhook
{
    public const SOURCE = 'RightAlly Boost lead forms';

    /** After publishing a Boost instant-form ad: listen on RightAlly's Page and remember whose the form is. */
    public function register(Campaign $c, string $formId): void
    {
        $account = app(Boost::class)->account();
        $page = Http::withToken($account['access_token'])->acceptJson()->timeout(20)->get(MetaApi::GRAPH.$account['page_id'], ['fields' => 'access_token']);
        $pageToken = (string) ($page->json('access_token') ?? '');
        if ($pageToken !== '') {
            Http::asForm()->timeout(20)->post(MetaApi::GRAPH.$account['page_id'].'/subscribed_apps', ['subscribed_fields' => 'leadgen', 'access_token' => $pageToken]);
        }
        DB::connection(Connections::control())->table('ads_boost_forms')->updateOrInsert(['form_id' => $formId],
            ['tenant_id' => $c->tenant_id, 'campaign_id' => $c->id, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function forget(string $formId): void
    {
        DB::connection(Connections::control())->table('ads_boost_forms')->where('form_id', $formId)->delete();
    }

    public function take(string $app, array $payload): bool
    {
        if ($app !== 'facebook_leads' || ! app(Boost::class)->ready() || (string) ($payload['page_id'] ?? '') !== app(Boost::class)->account()['page_id']) {
            return false;
        }
        $form = DB::connection(Connections::control())->table('ads_boost_forms')->where('form_id', (string) ($payload['form_id'] ?? ''))->first();
        $tenant = $form !== null ? Tenant::query()->whereKey($form->tenant_id)->where('status', 'active')->first() : null;
        if ($form === null || $tenant === null || ! ctype_digit((string) ($payload['leadgen_id'] ?? ''))) {
            return false;
        }
        app(TenantContext::class)->runAs($tenant, fn () => ReceiveBoostLead::dispatch((int) $form->campaign_id, (string) $payload['leadgen_id']));

        return true;
    }

    /** Reads one lead with RightAlly's token and gives it to this brokerage's lead intake (once, by Meta's lead id). */
    public function receive(Campaign $c, string $leadgenId): void
    {
        $account = app(Boost::class)->account();
        $page = Http::withToken($account['access_token'])->acceptJson()->timeout(20)->get(MetaApi::GRAPH.$account['page_id'], ['fields' => 'access_token']);
        $token = (string) ($page->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException('RightAlly Boost can no longer read its Page\'s leads.');
        }
        $r = Http::timeout(20)->get(MetaApi::GRAPH.$leadgenId, ['fields' => 'field_data,created_time,form_id', 'access_token' => $token]);
        $r->throw();
        $fields = [];
        foreach ((array) $r->json('field_data', []) as $f) {
            if (is_array($f) && is_string($f['name'] ?? null)) {
                $fields[strtolower($f['name'])] = trim((string) (((array) ($f['values'] ?? []))[0] ?? ''));
            }
        }
        $extra = array_diff_key($fields, array_flip(['full_name', 'first_name', 'last_name', 'email', 'phone_number', 'zip_code', 'post_code']));
        $source = LeadSource::query()->where('name', self::SOURCE)->where('source', Source::Facebook->value)->first()
            ?? LeadSource::query()->create(['name' => self::SOURCE, 'kind' => 'api', 'source' => Source::Facebook, 'token' => Str::random(40), 'created_by' => $c->user_id]);
        app(LeadIntake::class)->receive($source, [
            'external_id' => $leadgenId, 'name' => $fields['full_name'] ?? '', 'first_name' => $fields['first_name'] ?? '', 'last_name' => $fields['last_name'] ?? '',
            'email' => $fields['email'] ?? '', 'phone' => $fields['phone_number'] ?? '', 'zip' => $fields['zip_code'] ?? $fields['post_code'] ?? '',
            'message' => trim('About '.$c->listing_label."\n".implode("\n", array_map(fn (string $k, string $v): string => Str::headline($k).': '.$v, array_keys($extra), $extra))),
            'source' => Source::Facebook->value, 'utm_source' => 'facebook', 'utm_medium' => 'lead_ad', 'utm_campaign' => 'rightally-'.strtolower($c->ulid),
        ], null, User::query()->find($c->user_id));
    }
}
