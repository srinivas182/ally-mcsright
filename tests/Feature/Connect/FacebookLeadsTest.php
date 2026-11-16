<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Connections as ConnectConnections;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;

/* Sprint C3a: Facebook Lead Ads (one click; leads within seconds through Meta's one webhook address, routed to the
   brokerage that owns the Page) and the Realtor.com, Zillow and Homes.com tiles (forwarding email). */

const FB_HOOK = 'https://auth.rightally.app/connect/apps/facebook_leads/webhook';

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Jane']);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_leads'), true,
        ['client_id' => '123', 'client_secret' => 'app-secret', 'verify_token' => 'vt-xyz'], null, User::factory()->make(['id' => 999999]));
});

function fbConnect(User $as): Connection
{
    Http::fake([
        'graph.facebook.com/v21.0/oauth/access_token*' => fn (HttpRequest $r) => Http::response(str_contains($r->url(), 'fb_exchange_token') || ($r->data()['grant_type'] ?? '') === 'fb_exchange_token'
            ? ['access_token' => 'long-user', 'expires_in' => 5184000] : ['access_token' => 'short-user', 'expires_in' => 3600]),
        'graph.facebook.com/v21.0/me?*' => Http::response(['name' => 'Olivia', 'email' => 'olivia@fb.test']),
        'graph.facebook.com/v21.0/me/accounts*' => Http::response(['data' => [['id' => '111', 'name' => 'Acme Realty', 'access_token' => 'page-111'],
            ['id' => '222', 'name' => 'Acme Austin', 'access_token' => 'page-222']]]),
        'graph.facebook.com/v21.0/*/subscribed_apps*' => Http::response(['success' => true]),
    ]);
    $start = test()->actingAs($as)->post('/apps/facebook_leads/oauth', [], ['X-Inertia' => 'true']);
    $to = (string) $start->headers->get('X-Inertia-Location');
    expect($to)->toStartWith('https://www.facebook.com/v21.0/dialog/oauth?')->toContain('leads_retrieval');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $q);
    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    test()->actingAs($as)->get($home)->assertRedirect();

    return Connection::query()->where('app_key', 'facebook_leads')->sole();
}

function fbLead(string $page, string $leadgen, ?string $secret = 'app-secret'): TestResponse
{
    $raw = (string) json_encode(['object' => 'page', 'entry' => [['id' => $page, 'changes' => [['field' => 'leadgen', 'value' => ['page_id' => $page, 'leadgen_id' => $leadgen, 'form_id' => '9']]]]]]);
    $headers = ['CONTENT_TYPE' => 'application/json'] + ($secret !== null ? ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret)] : []);

    return test()->call('POST', FB_HOOK, [], [], [], $headers, $raw);
}

it('shows HQ the webhook address and answers Meta\'s check only with the right verify token', function (): void {
    $this->get(FB_HOOK.'?hub.mode=subscribe&hub.verify_token=vt-xyz&hub.challenge=4242')->assertOk()->assertSeeText('4242');
    $this->get(FB_HOOK.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=4242')->assertForbidden();
});

it('listens on every Page after one click, keeping Page tokens encrypted', function (): void {
    $c = fbConnect($this->owner);
    expect($c->settings['pages'])->toBe(['111' => 'Acme Realty', '222' => 'Acme Austin'])
        ->and(json_decode($c->credentials['pages'], true))->toBe(['111' => 'page-111', '222' => 'page-222'])
        ->and($c->credentials['long_lived'])->toBe('1')
        ->and(DB::connection(Connections::control())->table('connect_routes')->where('app_key', 'facebook_leads')->pluck('external_key')->sort()->values()->all())->toBe(['111', '222']);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '111/subscribed_apps') && $r['subscribed_fields'] === 'leadgen' && $r['access_token'] === 'page-111');
    expect((string) DB::table('connect_connections')->where('id', $c->id)->value('credentials'))->not->toContain('page-111');
});

it('turns each signed lead into one lead, routed by the brokerage, with the form answers', function (): void {
    fbConnect($this->owner);
    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/v21.0/777*' => Http::response(['field_data' => [['name' => 'full_name', 'values' => ['Rosa Diaz']], ['name' => 'email', 'values' => ['rosa@x.test']],
        ['name' => 'phone_number', 'values' => ['+15125550101']], ['name' => 'when_are_you_buying?', 'values' => ['3 months']]], 'campaign_name' => 'RightAlly: 201 Pine Rd · rightally-01jabcdefghjkmnpqrstvwxyz0', 'ad_name' => 'Zilker']),
    ]);
    fbLead('111', '777')->assertOk();
    fbLead('111', '777')->assertOk(); // Meta retries: still one lead

    $lead = Lead::query()->sole();
    $contact = Contact::query()->sole();
    expect([$contact->first_name, $contact->last_name, $contact->primary_email])->toBe(['Rosa', 'Diaz', 'rosa@x.test'])
        ->and(LeadSource::query()->find($lead->source_id)?->name)->toBe('Facebook Lead Ads')
        ->and($lead->external_id)->toBe('777')->and($lead->message)->toContain('When Are You Buying?: 3 months')
        ->and($lead->utm['utm_campaign'] ?? null)->toBe('rightally-01jabcdefghjkmnpqrstvwxyz0'); // a RightAlly ad's lead is credited to that ad
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/777?') && str_contains($r->url(), 'access_token=page-111'));
});

it('refuses unsigned webhooks and ignores Pages nobody connected', function (): void {
    fbConnect($this->owner);
    Http::swap(new Factory);
    Http::fake();
    fbLead('111', '777', null)->assertUnauthorized();
    fbLead('111', '777', 'wrong')->assertUnauthorized();
    fbLead('999', '778')->assertOk();
    Http::assertNothingSent();
    expect(Lead::query()->count())->toBe(0);
});

it('gives an agent\'s own Page leads to that agent, and stops listening when disconnected', function (): void {
    $c = fbConnect($this->agent);
    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/v21.0/555*' => Http::response(['field_data' => [['name' => 'full_name', 'values' => ['Sam Lee']], ['name' => 'email', 'values' => ['sam@x.test']]]]),
        'graph.facebook.com/*' => Http::response(['success' => true])]);
    fbLead('222', '555')->assertOk();
    expect(Lead::query()->sole()->assigned_user_id)->toBe($this->agent->id);

    app(ConnectConnections::class)->disconnect($c, $this->agent);
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '111/subscribed_apps'));
    expect(DB::connection(Connections::control())->table('connect_routes')->count())->toBe(0);
});

it('shows Realtor.com, Zillow and Homes.com as ready, with plain steps and the forwarding address', function (): void {
    $this->actingAs($this->owner)->post('/leads/sources', ['name' => 'Portals', 'kind' => 'email', 'source' => 'zillow']);
    foreach (['realtor_com', 'zillow', 'homes_com'] as $key) {
        $this->actingAs($this->owner)->get('/apps/'.$key)->assertInertia(fn (Assert $p) => $p->where('app.status', 'available')->where('app.auth', 'email')
            ->where('app.guide.steps.0', 'Copy your RightAlly lead address below (or make one under Leads → Settings).')
            ->where('help.emails', fn ($e): bool => count($e) === 1));
    }
    expect(app(AppCatalog::class)->find('zillow')?->setup['partner'])->toContain('Zillow CRM partners');
});
